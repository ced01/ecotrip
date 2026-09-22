export function validateSearch(request, today) {
  const errors = {};
  if (!request.originId) errors.originId = 'Choisissez un point de départ.';
  if (!request.destinationId) errors.destinationId = 'Choisissez une destination.';
  else if (request.destinationId === request.originId) errors.destinationId = 'La destination doit être différente du départ.';
  if (!request.departureDate) errors.departureDate = 'Choisissez une date de départ.';
  else if (!isIsoDate(request.departureDate)) errors.departureDate = 'Choisissez une date de départ valide.';
  else if (request.departureDate < today) errors.departureDate = 'La date de départ ne peut pas être passée.';
  if (request.returnDate && !isIsoDate(request.returnDate)) errors.returnDate = 'Choisissez une date de retour valide.';
  else if (request.returnDate && isIsoDate(request.departureDate) && request.returnDate < request.departureDate) errors.returnDate = 'La date de retour doit suivre le départ.';
  if (!Number.isInteger(request.travelers) || request.travelers < 1 || request.travelers > 9) errors.travelers = 'Choisissez entre 1 et 9 voyageurs.';
  if (!Array.isArray(request.modes) || request.modes.length === 0) errors.modes = 'Choisissez au moins un mode de transport.';
  return errors;
}

function isIsoDate(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value ?? '')) return false;
  const [year, month, day] = value.split('-').map(Number);
  const date = new Date(Date.UTC(year, month - 1, day));
  return date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day;
}

export class JourneyProblem extends Error {
  constructor(problem, status) {
    super('Journey API problem');
    this.name = 'JourneyProblem';
    this.status = Number(problem?.status) || status;
    this.code = typeof problem?.code === 'string' ? problem.code : 'unexpected_response';
    this.detail = typeof problem?.detail === 'string' ? problem.detail : 'La requête ne peut pas être traitée.';
    this.violations = Array.isArray(problem?.violations) ? problem.violations : [];
  }
}

export class HttpJourneyAdapter {
  constructor(endpoint = '/api/v1/journeys/search', fetcher = globalThis.fetch?.bind(globalThis)) {
    this.endpoint = endpoint;
    this.fetcher = fetcher;
  }

  async search(request, { signal } = {}) {
    if (!this.fetcher) throw new Error('Fetch unavailable');
    const wireRequest = { ...request, returnDate: request.returnDate || null };
    const response = await this.fetcher(this.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json, application/problem+json' },
      body: JSON.stringify(wireRequest),
      signal,
    });
    let payload;
    try { payload = await response.json(); } catch { throw new JourneyProblem({ code: 'unexpected_response', detail: 'Réponse du service illisible.' }, response.status); }
    if (!response.ok) throw new JourneyProblem(payload, response.status);
    return normalizeJourneyResponse(payload);
  }
}

const directionStatuses = new Set(['complete', 'partial', 'empty', 'out_of_coverage', 'unavailable', 'not_requested']);

export function normalizeJourneyResponse(payload) {
  if (!payload || typeof payload !== 'object' || !payload.outbound || !payload.inbound) {
    throw new JourneyProblem({ code: 'unexpected_response', detail: 'Réponse du service incomplète.' }, 502);
  }
  const direction = value => {
    if (!value || !directionStatuses.has(value.status) || !Array.isArray(value.itineraries)) {
      throw new JourneyProblem({ code: 'unexpected_response', detail: 'État de trajet inconnu.' }, 502);
    }
    return { status: value.status, itineraries: value.itineraries, warnings: Array.isArray(value.warnings) ? value.warnings : [] };
  };
  return {
    kind: 'results',
    dataMode: payload.dataMode,
    request: payload.request,
    outbound: direction(payload.outbound),
    inbound: direction(payload.inbound),
    sources: Array.isArray(payload.sources) ? payload.sources : [],
    warnings: Array.isArray(payload.warnings) ? payload.warnings : [],
  };
}

function hasCompleteCarbon(emissions) {
  return emissions && emissions.comparable === true
    && typeof emissions.comparisonKey === 'string' && emissions.comparisonKey.length > 0
    && Number.isFinite(emissions.kgCO2ePerTraveler)
    && Number.isFinite(emissions.kgCO2eGroup)
    && Number.isFinite(emissions.coveredDistanceKm)
    && Number.isFinite(emissions.totalDistanceKm)
    && emissions.coveredDistanceKm === emissions.totalDistanceKm
    && !['partial', 'unavailable', 'missing'].includes(emissions.status);
}

export function carbonSortEligibility(itineraries) {
  const candidates = itineraries.filter(item => hasCompleteCarbon(item.emissions));
  const comparisonKey = candidates[0]?.emissions.comparisonKey ?? null;
  const eligibleIds = candidates.filter(item => item.emissions.comparisonKey === comparisonKey).map(item => item.id);
  const eligible = new Set(eligibleIds);
  return { eligibleIds, excludedIds: itineraries.filter(item => !eligible.has(item.id)).map(item => item.id), comparisonKey };
}

export function sortItineraries(itineraries, criterion) {
  const indexed = itineraries.map((item, index) => ({ item, index }));
  if (criterion === 'carbon') {
    const eligible = new Set(carbonSortEligibility(itineraries).eligibleIds);
    indexed.sort((a, b) => {
      const aEligible = eligible.has(a.item.id); const bEligible = eligible.has(b.item.id);
      if (aEligible !== bEligible) return aEligible ? -1 : 1;
      if (aEligible) return a.item.emissions.kgCO2ePerTraveler - b.item.emissions.kgCO2ePerTraveler || a.index - b.index;
      return a.index - b.index;
    });
  } else if (criterion === 'duration') {
    indexed.sort((a, b) => finiteOrInfinity(a.item.durationMinutes) - finiteOrInfinity(b.item.durationMinutes) || a.index - b.index);
  } else if (criterion === 'transfers') {
    indexed.sort((a, b) => finiteOrInfinity(a.item.transfers) - finiteOrInfinity(b.item.transfers) || a.index - b.index);
  }
  return indexed.map(({ item }) => item);
}

function finiteOrInfinity(value) { return Number.isFinite(value) ? value : Number.POSITIVE_INFINITY; }

export class SearchFlow {
  #sequence = 0;
  #controller = null;

  constructor(adapter, render) { this.adapter = adapter; this.render = render; }

  async search(request) {
    const sequence = ++this.#sequence;
    this.#controller?.abort();
    this.#controller = new AbortController();
    this.render({ kind: 'loading' });
    try {
      const result = await this.adapter.search(request, { signal: this.#controller.signal });
      if (sequence === this.#sequence) this.render(result);
    } catch (error) {
      if (sequence !== this.#sequence || error?.name === 'AbortError') return;
      if (error?.name === 'JourneyProblem') {
        this.render({ kind: 'problem', status: error.status, code: error.code, detail: error.detail, violations: error.violations });
      } else {
        this.render({ kind: 'network_error', message: 'Connexion au service impossible. Vérifiez votre réseau puis réessayez.' });
      }
    }
  }
}
