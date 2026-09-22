export const TRIP_PLAN_STORAGE_KEY = 'ecotrip.trip-plan.v1';
export const TRIP_PLAN_SCHEMA_VERSION = 1;

const accommodationStatuses = new Set(['complete', 'empty', 'out_of_coverage']);
const idPattern = /^[a-z0-9][a-z0-9_-]{0,63}$/;
const isObject = value => value !== null && typeof value === 'object' && !Array.isArray(value);
const isText = value => typeof value === 'string' && value.length > 0;
const isNullableText = value => value === null || typeof value === 'string';
const isFiniteNonNegative = value => Number.isFinite(value) && value >= 0;
const isIsoDate = value => typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value);

export class AccommodationProblem extends Error {
  constructor(problem, status) {
    const detail = typeof problem?.detail === 'string' ? problem.detail : 'La requête ne peut pas être traitée.';
    super(detail);
    this.name = 'AccommodationProblem';
    this.status = Number(problem?.status) || status;
    this.code = typeof problem?.code === 'string' ? problem.code : 'unexpected_response';
    this.detail = detail;
    this.violations = Array.isArray(problem?.violations) ? problem.violations : [];
  }
}

export class HttpAccommodationAdapter {
  constructor(endpoint = '/api/v1/accommodations', fetcher = globalThis.fetch?.bind(globalThis)) {
    this.endpoint = endpoint;
    this.fetcher = fetcher;
  }

  async search(query, { signal } = {}) {
    if (!this.fetcher) throw new Error('Fetch unavailable');
    const parameters = new URLSearchParams();
    for (const name of ['destinationId', 'bicycleParking', 'publicTransportNearby', 'limit', 'offset']) {
      const value = query?.[name];
      if (value !== undefined && value !== null && value !== '') parameters.set(name, String(value));
    }
    const response = await this.fetcher(`${this.endpoint}?${parameters.toString()}`, {
      method: 'GET', headers: { Accept: 'application/json, application/problem+json' }, signal,
    });
    let payload;
    try { payload = await response.json(); } catch { throw new AccommodationProblem({ detail: 'Réponse du service illisible.' }, response.status); }
    if (!response.ok) throw new AccommodationProblem(payload, response.status);
    return normalizeAccommodationResponse(payload);
  }
}

export function normalizeAccommodationResponse(payload) {
  if (!isObject(payload) || !accommodationStatuses.has(payload.status) || !Array.isArray(payload.items)
      || !isObject(payload.page) || !Array.isArray(payload.sources) || !Array.isArray(payload.warnings)) {
    throw new AccommodationProblem({ detail: 'Réponse hébergements incomplète.' }, 502);
  }
  if (!payload.items.every(isAccommodation)) {
    throw new AccommodationProblem({ detail: 'Réponse hébergements incomplète.' }, 502);
  }
  return {
    kind: 'results', status: payload.status, items: payload.items, page: payload.page,
    sources: payload.sources, warnings: payload.warnings,
  };
}

export class AccommodationFlow {
  #sequence = 0;
  #controller = null;

  constructor(adapter, render) { this.adapter = adapter; this.render = render; }

  async search(query) {
    const sequence = ++this.#sequence;
    this.#controller?.abort();
    this.#controller = new AbortController();
    this.render({ kind: 'loading' });
    try {
      const result = await this.adapter.search(query, { signal: this.#controller.signal });
      if (sequence === this.#sequence) this.render(result);
    } catch (error) {
      if (sequence !== this.#sequence || error?.name === 'AbortError') return;
      if (error?.name === 'AccommodationProblem') {
        this.render({ kind: 'problem', status: error.status, code: error.code, detail: error.detail, violations: error.violations });
      } else {
        this.render({ kind: 'network_error', message: 'Connexion au catalogue impossible. Vérifiez votre réseau puis réessayez.' });
      }
    }
  }
}

export function accommodationQueryKey(query) {
  return [query?.destinationId ?? '', query?.bicycleParking || 'any', query?.publicTransportNearby || 'any'].join('|');
}

export function reconcileAccommodationSelection(selection, query, availableItems) {
  if (!selection) return { selection: null, reason: null };
  if (selection.destinationId !== query?.destinationId || selection.item?.destinationId !== query?.destinationId) {
    return { selection: null, reason: 'L’hébergement a été retiré du plan car la destination a changé.' };
  }
  if (!availableItems.some(item => item.id === selection.item?.id && item.destinationId === query.destinationId)) {
    return { selection: null, reason: 'L’hébergement a été retiré du plan car il ne correspond plus aux filtres.' };
  }
  return { selection, reason: null };
}

export function createTripPlan(journeys, outboundId, inboundId = null, accommodation = null, accommodationSources = []) {
  const outbound = journeys?.outbound?.itineraries?.find(item => item.id === outboundId && item.direction === 'outbound');
  const inbound = inboundId ? journeys?.inbound?.itineraries?.find(item => item.id === inboundId && item.direction === 'inbound') : null;
  if (!outbound || !isRequest(journeys?.request)) throw new TypeError('Un trajet aller compatible est requis.');
  if (inboundId && !inbound) throw new TypeError('Le trajet retour sélectionné est incompatible.');
  if (accommodation && accommodation.destinationId !== journeys.request.destinationId) throw new TypeError('L’hébergement est incompatible avec la destination.');
  const sources = deduplicateSources([...(journeys.sources ?? []), ...accommodationSources]);
  return { request: journeys.request, outbound, inbound: inbound ?? null, accommodation: accommodation ?? null, sources };
}

export function saveTripPlan(storage, plan, now = new Date()) {
  if (!storage || !validDate(now)) return { ok: false, reason: 'unavailable' };
  const saved = { ...plan, schemaVersion: TRIP_PLAN_SCHEMA_VERSION, savedAt: now.toISOString() };
  const validation = validateTripPlan(saved);
  if (validation !== null) return { ok: false, reason: validation };
  try {
    storage.setItem(TRIP_PLAN_STORAGE_KEY, JSON.stringify(saved));
    return { ok: true, plan: saved };
  } catch {
    return { ok: false, reason: 'unavailable' };
  }
}

export function restoreTripPlan(raw) {
  if (typeof raw !== 'string') return { ok: false, reason: 'absent' };
  let value;
  try { value = JSON.parse(raw); } catch { return { ok: false, reason: 'corrupt' }; }
  if (!isObject(value)) return { ok: false, reason: 'invalid' };
  if (value.schemaVersion !== TRIP_PLAN_SCHEMA_VERSION) return { ok: false, reason: 'unknown_version' };
  const reason = validateTripPlan(value);
  return reason === null ? { ok: true, plan: value } : { ok: false, reason };
}

export function loadTripPlan(storage) {
  if (!storage) return { ok: false, reason: 'unavailable' };
  try { return restoreTripPlan(storage.getItem(TRIP_PLAN_STORAGE_KEY)); } catch { return { ok: false, reason: 'unavailable' }; }
}

export function clearTripPlan(storage) {
  if (!storage) return { ok: false, reason: 'unavailable' };
  try { storage.removeItem(TRIP_PLAN_STORAGE_KEY); return { ok: true }; } catch { return { ok: false, reason: 'unavailable' }; }
}

export function formatSavedAge(savedAt, now = new Date()) {
  const saved = new Date(savedAt);
  if (!validDate(saved) || !validDate(now)) return 'date de sauvegarde inconnue';
  const minutes = Math.max(0, Math.floor((now.getTime() - saved.getTime()) / 60000));
  if (minutes < 1) return 'sauvegardé à l’instant';
  if (minutes < 60) return `sauvegardé il y a ${minutes} min`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `sauvegardé il y a ${hours} h`;
  const days = Math.floor(hours / 24);
  return `sauvegardé il y a ${days} j`;
}

function validateTripPlan(value) {
  const exact = ['schemaVersion', 'savedAt', 'request', 'outbound', 'inbound', 'accommodation', 'sources'];
  if (!hasExactKeys(value, exact) || typeof value.savedAt !== 'string' || !validDate(new Date(value.savedAt)) || !isRequest(value.request)
      || !isItinerary(value.outbound, 'outbound') || (value.inbound !== null && !isItinerary(value.inbound, 'inbound'))
      || (value.accommodation !== null && !isAccommodation(value.accommodation)) || !Array.isArray(value.sources) || !value.sources.every(isSource)) return 'invalid';
  const request = value.request;
  if (value.outbound.requestedDate !== request.departureDate || value.outbound.legs[0]?.originId !== request.originId
      || value.outbound.legs.at(-1)?.destinationId !== request.destinationId) return 'incompatible';
  if (value.inbound && (request.returnDate === null || value.inbound.requestedDate !== request.returnDate
      || value.inbound.legs[0]?.originId !== request.destinationId || value.inbound.legs.at(-1)?.destinationId !== request.originId)) return 'incompatible';
  if (value.accommodation && value.accommodation.destinationId !== request.destinationId) return 'incompatible';
  return null;
}

function isRequest(value) {
  return isObject(value) && idPattern.test(value.originId ?? '') && idPattern.test(value.destinationId ?? '')
    && value.originId !== value.destinationId && isIsoDate(value.departureDate)
    && (value.returnDate === null || isIsoDate(value.returnDate))
    && Number.isInteger(value.travelers) && value.travelers >= 1 && value.travelers <= 9
    && Array.isArray(value.modes) && value.modes.length > 0 && value.modes.every(isText);
}

function isItinerary(value, direction) {
  return isObject(value) && idPattern.test(value.id ?? '') && value.direction === direction && isIsoDate(value.requestedDate)
    && ['demo', 'real'].includes(value.dataStatus) && Array.isArray(value.legs) && value.legs.length > 0
    && value.legs.every(isLeg) && Number.isInteger(value.durationMinutes) && value.durationMinutes >= 0
    && Number.isInteger(value.transfers) && value.transfers >= 0 && isObject(value.emissions)
    && isObject(value.provenance) && Array.isArray(value.warnings) && value.warnings.every(value => typeof value === 'string');
}

function isLeg(value) {
  return isObject(value) && idPattern.test(value.id ?? '') && isText(value.mode) && idPattern.test(value.originId ?? '')
    && idPattern.test(value.destinationId ?? '') && Number.isInteger(value.durationMinutes) && value.durationMinutes >= 0
    && Number.isInteger(value.waitingMinutes) && value.waitingMinutes >= 0 && isObject(value.distance)
    && isObject(value.schedule) && isObject(value.provenance);
}

function isAccommodation(value) {
  return isObject(value) && idPattern.test(value.id ?? '') && isText(value.name) && idPattern.test(value.destinationId ?? '')
    && ['demo', 'real'].includes(value.dataStatus) && isObject(value.features)
    && [true, false, null].includes(value.features.bicycleParking) && [true, false, null].includes(value.features.publicTransportNearby)
    && (value.publicTransportDistanceMeters === null || (Number.isInteger(value.publicTransportDistanceMeters) && value.publicTransportDistanceMeters >= 0))
    && (value.price === null || isPrice(value.price)) && Array.isArray(value.evidence) && value.evidence.every(isEvidence)
    && isObject(value.provenance);
}

function isPrice(value) {
  return isObject(value) && isFiniteNonNegative(value.amount) && /^[A-Z]{3}$/.test(value.currency ?? '')
    && ['night_per_room', 'night_per_person'].includes(value.basis) && isIsoDate(value.asOf)
    && idPattern.test(value.sourceId ?? '') && ['demo', 'verified', 'unverified'].includes(value.dataStatus);
}

function isEvidence(value) {
  return isObject(value) && idPattern.test(value.id ?? '') && isText(value.claim)
    && ['declaration', 'certification'].includes(value.kind) && ['declared', 'unverified', 'verified', 'expired', 'demo'].includes(value.status)
    && isNullableText(value.organization) && isNullableText(value.referenceUrl) && isNullableText(value.validFrom)
    && isNullableText(value.validUntil) && isNullableText(value.checkedAt) && idPattern.test(value.sourceId ?? '');
}

function isSource(value) {
  return isObject(value) && idPattern.test(value.id ?? '') && isText(value.publisher) && isNullableText(value.url)
    && isNullableText(value.license) && isNullableText(value.accessedAt) && isNullableText(value.version)
    && typeof value.reuseNotes === 'string' && ['demo', 'verified', 'unverified'].includes(value.dataStatus);
}

function deduplicateSources(sources) {
  const result = new Map();
  for (const source of sources) if (isSource(source) && !result.has(source.id)) result.set(source.id, source);
  return [...result.values()];
}
function validDate(value) { return value instanceof Date && Number.isFinite(value.getTime()); }
function hasExactKeys(value, expected) { const keys = Object.keys(value).sort(); return keys.length === expected.length && keys.every((key, index) => key === [...expected].sort()[index]); }
