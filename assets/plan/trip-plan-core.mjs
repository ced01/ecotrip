export const TRIP_PLAN_STORAGE_KEY = 'ecotrip.trip-plan.v1';
export const TRIP_PLAN_SCHEMA_VERSION = 1;

const accommodationStatuses = new Set(['complete', 'empty', 'out_of_coverage']);
const modes = new Set(['train', 'coach', 'walk', 'public_transport', 'bicycle', 'carpool', 'flight']);
const idPattern = /^[a-z0-9][a-z0-9_-]{0,63}$/;
const MAX_COLLECTION = 100;
const MAX_TEXT = 10_000;
const MAX_SNAPSHOT_BYTES = 1_000_000;
const isObject = value => value !== null && typeof value === 'object' && !Array.isArray(value);
const isText = value => typeof value === 'string' && value.length > 0 && value.length <= MAX_TEXT;
const isNullableText = value => value === null || (typeof value === 'string' && value.length <= MAX_TEXT);
const isFiniteNonNegative = value => Number.isFinite(value) && value >= 0;
const isIsoDate = value => {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const date = new Date(`${value}T00:00:00.000Z`);
  return validDate(date) && date.toISOString().slice(0, 10) === value;
};
const isIsoDateTime = value => typeof value === 'string' && value.length <= 100 && /^\d{4}-\d{2}-\d{2}T/.test(value)
  && isIsoDate(value.slice(0, 10)) && validDate(new Date(value));
const isNullableDate = value => value === null || isIsoDate(value);
const isNullableDateTime = value => value === null || isIsoDateTime(value);
const isHttpUrl = value => {
  if (value === null) return true;
  if (typeof value !== 'string' || value.length > 2048) return false;
  try { return ['http:', 'https:'].includes(new URL(value).protocol); } catch { return false; }
};
const isArrayOf = (value, validator, maximum = MAX_COLLECTION) => Array.isArray(value) && value.length <= maximum && value.every(validator);

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
  if (!isAccommodationResponse(payload)) {
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
  if (selection.queryKey !== accommodationQueryKey(query)) {
    return { selection: null, reason: 'L’hébergement a été retiré du plan car il ne correspond plus aux filtres.' };
  }
  if (!availableItems.some(item => item.id === selection.item?.id && item.destinationId === query.destinationId)) {
    return { selection: null, reason: 'L’hébergement a été retiré du plan car il ne correspond plus aux filtres.' };
  }
  return { selection, reason: null };
}

export function createTripPlan(journeys, outboundId, inboundId = null, accommodation = null, accommodationSources = [], accommodationWarnings = []) {
  const outbound = journeys?.outbound?.itineraries?.find(item => item.id === outboundId && item.direction === 'outbound');
  const inbound = inboundId ? journeys?.inbound?.itineraries?.find(item => item.id === inboundId && item.direction === 'inbound') : null;
  if (!outbound || !isRequest(journeys?.request)) throw new TypeError('Un trajet aller compatible est requis.');
  if (inboundId && !inbound) throw new TypeError('Le trajet retour sélectionné est incompatible.');
  if (accommodation && accommodation.destinationId !== journeys.request.destinationId) throw new TypeError('L’hébergement est incompatible avec la destination.');
  const sources = deduplicateSources([...(journeys.sources ?? []), ...accommodationSources]);
  const warnings = {
    journeys: copyWarnings(journeys.warnings), outbound: copyWarnings(journeys.outbound?.warnings),
    inbound: copyWarnings(journeys.inbound?.warnings), accommodation: copyWarnings(accommodationWarnings),
  };
  return { request: journeys.request, outbound, inbound: inbound ?? null, accommodation: accommodation ?? null, sources, warnings };
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
  if (raw.length > MAX_SNAPSHOT_BYTES) return { ok: false, reason: 'invalid' };
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
  const exact = ['schemaVersion', 'savedAt', 'request', 'outbound', 'inbound', 'accommodation', 'sources', 'warnings'];
  if (!hasExactKeys(value, exact) || value.schemaVersion !== TRIP_PLAN_SCHEMA_VERSION || !isIsoDateTime(value.savedAt)
      || !isRequest(value.request) || !isItinerary(value.outbound, 'outbound', value.request.travelers)
      || (value.inbound !== null && !isItinerary(value.inbound, 'inbound', value.request.travelers))
      || (value.accommodation !== null && !isAccommodation(value.accommodation))
      || !isArrayOf(value.sources, isSource) || !isPlanWarnings(value.warnings)) return 'invalid';
  const request = value.request;
  if (value.outbound.requestedDate !== request.departureDate || value.outbound.legs[0].originId !== request.originId
      || value.outbound.legs.at(-1).destinationId !== request.destinationId) return 'incompatible';
  if (value.inbound && (request.returnDate === null || value.inbound.requestedDate !== request.returnDate
      || value.inbound.legs[0].originId !== request.destinationId || value.inbound.legs.at(-1).destinationId !== request.originId)) return 'incompatible';
  if (value.accommodation && value.accommodation.destinationId !== request.destinationId) return 'incompatible';
  const sourceIds = new Set(value.sources.map(source => source.id));
  if (!allReferencedSources(value, sourceIds)) return 'invalid';
  return null;
}

function isRequest(value) {
  return isObject(value) && hasExactKeys(value, ['originId', 'destinationId', 'departureDate', 'returnDate', 'travelers', 'modes'])
    && idPattern.test(value.originId ?? '') && idPattern.test(value.destinationId ?? '') && value.originId !== value.destinationId
    && isIsoDate(value.departureDate) && isNullableDate(value.returnDate) && (value.returnDate === null || value.returnDate >= value.departureDate)
    && Number.isInteger(value.travelers) && value.travelers >= 1 && value.travelers <= 9
    && isArrayOf(value.modes, mode => modes.has(mode), 7) && value.modes.length > 0 && new Set(value.modes).size === value.modes.length;
}

function isItinerary(value, direction, travelers = 1) {
  if (!isObject(value) || !hasExactKeys(value, ['id', 'direction', 'requestedDate', 'dataStatus', 'legs', 'durationMinutes', 'transfers', 'emissions', 'provenance', 'warnings'])
      || !idPattern.test(value.id ?? '') || value.direction !== direction || !isIsoDate(value.requestedDate)
      || !['demo', 'real'].includes(value.dataStatus) || !isArrayOf(value.legs, isLeg, 100) || value.legs.length === 0
      || !Number.isInteger(value.durationMinutes) || value.durationMinutes < 0 || !Number.isInteger(value.transfers) || value.transfers < 0
      || !isEmissionEstimate(value.emissions, value.legs, travelers) || !isProvenance(value.provenance) || !isWarnings(value.warnings)) return false;
  if (value.durationMinutes !== value.legs.reduce((total, leg) => total + leg.durationMinutes + leg.waitingMinutes, 0)) return false;
  if (value.transfers > Math.max(0, value.legs.filter(leg => leg.mode !== 'walk').length - 1)) return false;
  return value.legs.every((leg, index) => index === 0 || value.legs[index - 1].destinationId === leg.originId);
}

function isLeg(value) {
  if (!isObject(value) || !hasExactKeys(value, ['id', 'mode', 'subtype', 'originId', 'destinationId', 'durationMinutes', 'waitingMinutes', 'distance', 'schedule', 'provenance'])
      || !idPattern.test(value.id ?? '') || !modes.has(value.mode) || !isNullableText(value.subtype)
      || !idPattern.test(value.originId ?? '') || !idPattern.test(value.destinationId ?? '') || value.originId === value.destinationId
      || !Number.isInteger(value.durationMinutes) || value.durationMinutes < 0 || !Number.isInteger(value.waitingMinutes) || value.waitingMinutes < 0
      || !isDistance(value.distance) || !isSchedule(value.schedule) || !isProvenance(value.provenance)) return false;
  return true;
}

function isDistance(value) {
  return isObject(value) && hasExactKeys(value, ['km', 'method', 'provenance'])
    && (value.km === null || isFiniteNonNegative(value.km))
    && ['scenario', 'routed', 'great_circle', 'unknown'].includes(value.method) && isProvenance(value.provenance);
}

function isSchedule(value) {
  if (!isObject(value) || !hasExactKeys(value, ['departureAt', 'arrivalAt', 'provenance'])
      || !isNullableDateTime(value.departureAt) || !isNullableDateTime(value.arrivalAt) || !isProvenance(value.provenance)) return false;
  if ((value.departureAt === null) !== (value.arrivalAt === null)) return false;
  return value.departureAt === null || new Date(value.arrivalAt) >= new Date(value.departureAt);
}

function isEmissionEstimate(value, itineraryLegs, travelers) {
  if (!isObject(value) || !hasExactKeys(value, ['status', 'kgCO2ePerTraveler', 'kgCO2eGroup', 'coveredDistanceKm', 'totalDistanceKm', 'comparable', 'comparisonKey', 'methodologyVersion', 'assumptions', 'legs', 'factors'])
      || !['complete', 'partial', 'unavailable', 'demo'].includes(value.status)
      || !isNullableNonNegative(value.kgCO2ePerTraveler) || !isNullableNonNegative(value.kgCO2eGroup)
      || !isFiniteNonNegative(value.coveredDistanceKm) || !isNullableNonNegative(value.totalDistanceKm)
      || typeof value.comparable !== 'boolean' || !isNullableText(value.comparisonKey) || !isText(value.methodologyVersion)
      || !isWarnings(value.assumptions) || !isArrayOf(value.legs, isLegEstimate) || !isArrayOf(value.factors, isFactor)) return false;
  if (value.totalDistanceKm !== null && value.coveredDistanceKm > value.totalDistanceKm) return false;
  if ((value.kgCO2ePerTraveler === null) !== (value.kgCO2eGroup === null)) return false;
  if (value.kgCO2ePerTraveler !== null && !almostEqual(value.kgCO2eGroup, value.kgCO2ePerTraveler * travelers)) return false;
  if (value.comparable && (!['complete', 'demo'].includes(value.status) || !isText(value.comparisonKey) || value.totalDistanceKm === null || !almostEqual(value.coveredDistanceKm, value.totalDistanceKm))) return false;
  if (!value.comparable && value.comparisonKey !== null) return false;
  const legIds = new Set(itineraryLegs.map(leg => leg.id));
  const factorIds = new Set(value.factors.map(factor => factor.id));
  if (factorIds.size !== value.factors.length || new Set(value.legs.map(estimate => estimate.legId)).size !== value.legs.length) return false;
  const knownDistance = itineraryLegs.every(leg => leg.distance.km !== null)
    ? itineraryLegs.reduce((total, leg) => total + leg.distance.km, 0) : null;
  if (knownDistance !== null && value.totalDistanceKm !== null && !almostEqual(knownDistance, value.totalDistanceKm)) return false;
  return value.legs.every(estimate => legIds.has(estimate.legId)
    && (estimate.factorId === null || factorIds.has(estimate.factorId))
    && ((estimate.kgCO2ePerTraveler === null) === (estimate.kgCO2eGroup === null))
    && (estimate.kgCO2ePerTraveler === null || almostEqual(estimate.kgCO2eGroup, estimate.kgCO2ePerTraveler * travelers)));
}

function isLegEstimate(value) {
  return isObject(value) && hasExactKeys(value, ['legId', 'status', 'kgCO2ePerTraveler', 'kgCO2eGroup', 'factorId', 'reason'])
    && idPattern.test(value.legId ?? '') && ['complete', 'unavailable', 'demo'].includes(value.status)
    && isNullableNonNegative(value.kgCO2ePerTraveler) && isNullableNonNegative(value.kgCO2eGroup)
    && (value.factorId === null || idPattern.test(value.factorId ?? '')) && isNullableText(value.reason);
}

function isFactor(value) {
  if (!isObject(value) || !hasExactKeys(value, ['id', 'value', 'unit', 'mode', 'subtype', 'geography', 'validFrom', 'validUntil', 'scope', 'occupancy', 'version', 'sourceId', 'status'])
      || !idPattern.test(value.id ?? '') || !isFiniteNonNegative(value.value)
      || !['kgCO2e/passenger-km', 'kgCO2e/vehicle-km'].includes(value.unit) || !modes.has(value.mode)
      || !isNullableText(value.subtype) || !isText(value.geography) || !isIsoDate(value.validFrom) || !isNullableDate(value.validUntil)
      || (value.validUntil !== null && value.validUntil < value.validFrom) || !['operation', 'life_cycle'].includes(value.scope)
      || !(value.occupancy === null || (Number.isFinite(value.occupancy) && value.occupancy > 0)) || !isText(value.version)
      || !idPattern.test(value.sourceId ?? '') || !['verified', 'synthetic_test'].includes(value.status)) return false;
  return value.unit !== 'kgCO2e/vehicle-km' || value.occupancy !== null;
}

function isAccommodation(value) {
  return isObject(value) && hasExactKeys(value, ['id', 'name', 'destinationId', 'dataStatus', 'features', 'publicTransportDistanceMeters', 'price', 'evidence', 'provenance'])
    && idPattern.test(value.id ?? '') && isText(value.name) && idPattern.test(value.destinationId ?? '')
    && ['demo', 'real'].includes(value.dataStatus) && isFeatures(value.features)
    && (value.publicTransportDistanceMeters === null || (Number.isInteger(value.publicTransportDistanceMeters) && value.publicTransportDistanceMeters >= 0))
    && (value.price === null || isPrice(value.price)) && isArrayOf(value.evidence, isEvidence) && isProvenance(value.provenance);
}

function isFeatures(value) {
  return isObject(value) && hasExactKeys(value, ['bicycleParking', 'publicTransportNearby'])
    && [true, false, null].includes(value.bicycleParking) && [true, false, null].includes(value.publicTransportNearby);
}

function isPrice(value) {
  return isObject(value) && hasExactKeys(value, ['amount', 'currency', 'basis', 'asOf', 'sourceId', 'dataStatus'])
    && isFiniteNonNegative(value.amount) && /^[A-Z]{3}$/.test(value.currency ?? '')
    && ['night_per_room', 'night_per_person'].includes(value.basis) && isIsoDate(value.asOf)
    && idPattern.test(value.sourceId ?? '') && ['demo', 'verified', 'unverified'].includes(value.dataStatus);
}

function isEvidence(value) {
  if (!isObject(value) || !hasExactKeys(value, ['id', 'claim', 'kind', 'status', 'organization', 'referenceUrl', 'validFrom', 'validUntil', 'checkedAt', 'sourceId'])
      || !idPattern.test(value.id ?? '') || !isText(value.claim) || !['declaration', 'certification'].includes(value.kind)
      || !['declared', 'unverified', 'verified', 'expired', 'demo'].includes(value.status) || !isNullableText(value.organization)
      || !isHttpUrl(value.referenceUrl) || !isNullableDate(value.validFrom) || !isNullableDate(value.validUntil)
      || !isNullableDate(value.checkedAt) || !idPattern.test(value.sourceId ?? '')) return false;
  if (value.validFrom !== null && value.validUntil !== null && value.validUntil < value.validFrom) return false;
  if (value.kind === 'declaration' && ['verified', 'expired'].includes(value.status)) return false;
  if (value.kind === 'certification' && value.status === 'declared') return false;
  if (value.status === 'verified') {
    if (value.kind !== 'certification' || !isText(value.organization) || typeof value.referenceUrl !== 'string'
        || !value.referenceUrl.startsWith('https://') || !isIsoDate(value.checkedAt)) return false;
    if (value.validFrom !== null && value.checkedAt < value.validFrom) return false;
    if (value.validUntil !== null && value.checkedAt > value.validUntil) return false;
    if (value.validUntil !== null && value.validUntil < new Date().toISOString().slice(0, 10)) return false;
  }
  return value.status !== 'expired' || value.validUntil !== null;
}

function isSource(value) {
  return isObject(value) && hasExactKeys(value, ['id', 'publisher', 'url', 'license', 'accessedAt', 'version', 'reuseNotes', 'dataStatus'])
    && idPattern.test(value.id ?? '') && isText(value.publisher) && isHttpUrl(value.url)
    && isNullableText(value.license) && isNullableDate(value.accessedAt) && isNullableText(value.version)
    && typeof value.reuseNotes === 'string' && value.reuseNotes.length <= MAX_TEXT && ['demo', 'verified', 'unverified'].includes(value.dataStatus);
}

function isProvenance(value) {
  return isObject(value) && hasExactKeys(value, ['status', 'sourceIds', 'asOf', 'note'])
    && ['demo', 'verified', 'unverified', 'unknown'].includes(value.status)
    && isArrayOf(value.sourceIds, sourceId => idPattern.test(sourceId), MAX_COLLECTION)
    && isNullableDateTime(value.asOf) && typeof value.note === 'string' && value.note.length <= MAX_TEXT;
}

function isPage(value) {
  return isObject(value) && hasExactKeys(value, ['limit', 'offset', 'total'])
    && Number.isInteger(value.limit) && value.limit >= 1 && value.limit <= 50
    && Number.isInteger(value.offset) && value.offset >= 0 && value.offset <= 10_000
    && Number.isInteger(value.total) && value.total >= 0;
}

function isAccommodationResponse(value) {
  return isObject(value) && hasExactKeys(value, ['status', 'items', 'page', 'sources', 'warnings'])
    && accommodationStatuses.has(value.status) && isArrayOf(value.items, isAccommodation, 50)
    && isPage(value.page) && isArrayOf(value.sources, isSource) && new Set(value.sources.map(source => source.id)).size === value.sources.length
    && isWarnings(value.warnings);
}

function isPlanWarnings(value) {
  return isObject(value) && hasExactKeys(value, ['journeys', 'outbound', 'inbound', 'accommodation'])
    && Object.values(value).every(isWarnings);
}

function isWarnings(value) { return isArrayOf(value, item => typeof item === 'string' && item.length <= MAX_TEXT); }
function copyWarnings(value) { return isWarnings(value) ? [...value] : []; }
function isNullableNonNegative(value) { return value === null || isFiniteNonNegative(value); }
function almostEqual(left, right) { return Math.abs(left - right) <= 1e-9 * Math.max(1, Math.abs(left), Math.abs(right)); }
function allReferencedSources(plan, sourceIds) {
  const provenances = [plan.outbound.provenance, ...plan.outbound.legs.flatMap(leg => [leg.provenance, leg.distance.provenance, leg.schedule.provenance])];
  if (plan.inbound) provenances.push(plan.inbound.provenance, ...plan.inbound.legs.flatMap(leg => [leg.provenance, leg.distance.provenance, leg.schedule.provenance]));
  if (plan.accommodation) provenances.push(plan.accommodation.provenance);
  if (!provenances.every(provenance => provenance.sourceIds.every(id => sourceIds.has(id)))) return false;
  const sourceReferences = [
    ...plan.outbound.emissions.factors.map(factor => factor.sourceId),
    ...(plan.inbound?.emissions.factors ?? []).map(factor => factor.sourceId),
    ...(plan.accommodation?.evidence ?? []).map(evidence => evidence.sourceId),
    ...(plan.accommodation?.price ? [plan.accommodation.price.sourceId] : []),
  ];
  return sourceReferences.every(id => sourceIds.has(id));
}

function deduplicateSources(sources) {
  const result = new Map();
  for (const source of sources) if (isSource(source) && !result.has(source.id)) result.set(source.id, source);
  return [...result.values()];
}
function validDate(value) { return value instanceof Date && Number.isFinite(value.getTime()); }
function hasExactKeys(value, expected) {
  if (!isObject(value)) return false;
  const keys = Object.keys(value).sort();
  const sorted = [...expected].sort();
  return keys.length === sorted.length && keys.every((key, index) => key === sorted[index]);
}
