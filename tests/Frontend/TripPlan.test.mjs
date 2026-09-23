import test from 'node:test';
import assert from 'node:assert/strict';
import {
  AccommodationFlow,
  HttpAccommodationAdapter,
  accommodationQueryKey,
  createTripPlan,
  formatSavedAge,
  normalizeAccommodationResponse,
  reconcileAccommodationSelection,
  restoreTripPlan,
  saveTripPlan,
  loadTripPlan,
  clearTripPlan,
  TRIP_PLAN_LEGACY_STORAGE_KEY,
  TRIP_PLAN_STORAGE_KEY,
} from '../../assets/plan/trip-plan-core.mjs';

const request = {
  originId: 'demo-paris', destinationId: 'demo-lyon', departureDate: '2027-01-15',
  returnDate: '2027-01-18', travelers: 2, modes: ['train', 'coach'],
};
const provenance = { status: 'demo', sourceIds: ['demo-source'], asOf: null, note: 'Synthétique.' };
const source = { id: 'demo-source', publisher: 'Démo', url: null, license: null, accessedAt: null, version: '1', reuseNotes: 'Test.', dataStatus: 'demo' };
const itinerary = (id, direction) => ({
  id, direction, requestedDate: direction === 'outbound' ? request.departureDate : request.returnDate,
  dataStatus: 'demo', legs: [{ id: `${id}-leg`, mode: 'train', subtype: null, originId: direction === 'outbound' ? request.originId : request.destinationId, destinationId: direction === 'outbound' ? request.destinationId : request.originId, durationMinutes: 120, waitingMinutes: 0, distance: { km: 100, method: 'scenario', provenance }, schedule: { departureAt: null, arrivalAt: null, provenance }, provenance }],
  durationMinutes: 120, transfers: 0,
  emissions: { status: 'demo', kgCO2ePerTraveler: 2, kgCO2eGroup: 4, coveredDistanceKm: 100, totalDistanceKm: 100, comparable: true, comparisonKey: 'demo-key', methodologyVersion: 'v1', assumptions: ['Hypothèse démo'], legs: [], factors: [] },
  provenance, warnings: ['Horaire non réel.'],
});
const accommodation = {
  id: 'demo-stay', name: 'Séjour démo', destinationId: 'demo-lyon', dataStatus: 'demo',
  features: { bicycleParking: null, publicTransportNearby: true }, publicTransportDistanceMeters: 180,
  price: { amount: 89, currency: 'EUR', basis: 'night_per_room', asOf: '2026-09-01', sourceId: 'demo-source', dataStatus: 'demo' },
  evidence: [{ id: 'declaration', claim: 'Déclaration synthétique', kind: 'declaration', status: 'declared', organization: null, referenceUrl: null, validFrom: null, validUntil: null, checkedAt: null, sourceId: 'demo-source' }],
  provenance,
};
const accommodationPayload = { status: 'complete', items: [accommodation], page: { limit: 20, offset: 0, total: 1 }, sources: [source], warnings: ['Aucune disponibilité annoncée.'] };
const journeys = {
  request, outbound: { status: 'complete', itineraries: [itinerary('out', 'outbound')], warnings: [] },
  inbound: { status: 'complete', itineraries: [itinerary('back', 'inbound')], warnings: [] },
  sources: [source], warnings: ['Données démo.'],
};

test('accommodation adapter sends only contractual destination and feature filters', async () => {
  let call;
  const adapter = new HttpAccommodationAdapter('/api/v1/accommodations', async (url, options) => {
    call = { url, options };
    return { ok: true, status: 200, json: async () => accommodationPayload };
  });
  const result = await adapter.search({ destinationId: 'demo-lyon', bicycleParking: 'unknown', publicTransportNearby: '', limit: 20 });
  assert.equal(call.url, '/api/v1/accommodations?destinationId=demo-lyon&bicycleParking=unknown&limit=20');
  assert.equal(call.options.method, 'GET');
  assert.equal(result.items[0].features.bicycleParking, null);
});

test('normalization preserves complete, empty and out-of-coverage statuses and rejects malformed payloads', () => {
  for (const status of ['complete', 'empty', 'out_of_coverage']) {
    const value = normalizeAccommodationResponse({ ...accommodationPayload, status, items: status === 'complete' ? [accommodation] : [] });
    assert.equal(value.status, status);
  }
  assert.throws(() => normalizeAccommodationResponse({ status: 'complete', items: 'hostile' }), /incomplète/i);
});

test('accommodation flow exposes loading, Problem JSON and redacted network errors', async () => {
  const states = [];
  await new AccommodationFlow({ search: async () => { throw Object.assign(new Error('secret'), { name: 'AccommodationProblem', status: 503, code: 'provider_unavailable', detail: 'Indisponible.', violations: [] }); } }, state => states.push(state)).search({ destinationId: 'demo-lyon' });
  assert.deepEqual(states.map(({ kind }) => kind), ['loading', 'problem']);
  const network = [];
  await new AccommodationFlow({ search: async () => { throw new Error('network secret'); } }, state => network.push(state)).search({ destinationId: 'demo-lyon' });
  assert.equal(network.at(-1).kind, 'network_error');
  assert.equal(JSON.stringify(network.at(-1)).includes('network secret'), false);
});

test('selection is invalidated explicitly when destination or filtered results become incompatible', () => {
  const selected = { item: accommodation, destinationId: 'demo-lyon', queryKey: 'demo-lyon|any|any' };
  assert.equal(reconcileAccommodationSelection(selected, { destinationId: 'demo-lyon' }, [accommodation]).selection.item.id, 'demo-stay');
  assert.match(reconcileAccommodationSelection(selected, { destinationId: 'demo-paris' }, []).reason, /destination/i);
  assert.match(reconcileAccommodationSelection(selected, { destinationId: 'demo-lyon' }, []).reason, /filtres/i);
});

test('trip plan supports optional return and accommodation while preserving evidence and sources', () => {
  const withStay = createTripPlan(journeys, 'out', 'back', accommodation, accommodationPayload.sources);
  assert.equal(withStay.outbound.warnings[0], 'Horaire non réel.');
  assert.equal(withStay.outbound.emissions.assumptions[0], 'Hypothèse démo');
  assert.equal(withStay.accommodation.evidence[0].status, 'declared');
  assert.deepEqual(withStay.sources.map(({ id }) => id), ['demo-source']);
  const minimal = createTripPlan(journeys, 'out', null, null, []);
  assert.equal(minimal.inbound, null);
  assert.equal(minimal.accommodation, null);
});

test('voluntary persistence writes only v2, is restorable and reports age', () => {
  const memory = new Map();
  const storage = { setItem: (key, value) => memory.set(key, value), getItem: key => memory.get(key) ?? null, removeItem: key => memory.delete(key) };
  const plan = createTripPlan(journeys, 'out', 'back', accommodation, accommodationPayload.sources);
  const saved = saveTripPlan(storage, plan, new Date('2026-09-22T10:00:00.000Z'));
  assert.equal(saved.ok, true);
  const restored = loadTripPlan(storage);
  assert.equal(restored.ok, true);
  assert.equal(restored.plan.schemaVersion, 2);
  assert.equal(memory.has(TRIP_PLAN_STORAGE_KEY), true);
  assert.equal(memory.has(TRIP_PLAN_LEGACY_STORAGE_KEY), false);
  assert.equal(restored.plan.savedAt, '2026-09-22T10:00:00.000Z');
  assert.equal(restored.plan.accommodation.id, 'demo-stay');
  assert.equal(formatSavedAge(restored.plan.savedAt, new Date('2026-09-22T12:30:00.000Z')), 'sauvegardé il y a 2 h');
  assert.equal(clearTripPlan(storage).ok, true);
  assert.equal(loadTripPlan(storage).reason, 'absent');
});

test('restoration rejects corruption, unknown versions and incompatible destination data', () => {
  assert.equal(restoreTripPlan('{').reason, 'corrupt');
  const plan = { ...createTripPlan(journeys, 'out', null, accommodation, [source]), schemaVersion: 2, savedAt: '2026-09-22T10:00:00.000Z' };
  assert.equal(restoreTripPlan(JSON.stringify({ ...plan, schemaVersion: 99 })).reason, 'unknown_version');
  assert.equal(restoreTripPlan(JSON.stringify({ ...plan, accommodation: { ...accommodation, destinationId: 'demo-paris' } })).reason, 'incompatible');
  assert.equal(restoreTripPlan(JSON.stringify({ ...plan, outbound: { id: 'incomplete' } })).reason, 'invalid');
});

test('storage refusal never throws during save, load or clear', () => {
  const refused = { setItem: () => { throw new Error('denied'); }, getItem: () => { throw new Error('denied'); }, removeItem: () => { throw new Error('denied'); } };
  const plan = createTripPlan(journeys, 'out', null, null, []);
  assert.equal(saveTripPlan(refused, plan, new Date()).reason, 'unavailable');
  assert.equal(loadTripPlan(refused).reason, 'unavailable');
  assert.equal(clearTripPlan(refused).reason, 'unavailable');
});

test('catalogue normalization recursively enforces the AccommodationsResponse contract', () => {
  for (const payload of [
    { ...accommodationPayload, garbage: true },
    { ...accommodationPayload, page: { ...accommodationPayload.page, garbage: true } },
    { ...accommodationPayload, sources: [{ ...source, url: 'javascript:alert(1)' }] },
    { ...accommodationPayload, warnings: [42] },
    { ...accommodationPayload, items: [{ ...accommodation, provenance: { ...provenance, status: 'hostile' } }] },
    { ...accommodationPayload, items: [{ ...accommodation, price: { ...accommodation.price, asOf: '2026-02-30' } }] },
    { ...accommodationPayload, items: [{ ...accommodation, evidence: [{ ...accommodation.evidence[0], status: 'verified' }] }] },
  ]) assert.throws(() => normalizeAccommodationResponse(payload), /incomplète/i);
});

test('a changed accommodation query key invalidates selection before the next response', () => {
  const selected = { item: accommodation, destinationId: 'demo-lyon', queryKey: 'demo-lyon|any|any' };
  const query = { destinationId: 'demo-lyon', bicycleParking: 'true', publicTransportNearby: 'any' };
  assert.notEqual(accommodationQueryKey(query), selected.queryKey);
  assert.match(reconcileAccommodationSelection(selected, query, [accommodation]).reason, /filtres/i);
});

test('all four warning categories survive creation, save and restore', () => {
  const warnedJourneys = structuredClone(journeys);
  warnedJourneys.outbound.warnings = ['Catalogue aller partiel.'];
  warnedJourneys.inbound.warnings = ['Catalogue retour partiel.'];
  const plan = createTripPlan(warnedJourneys, 'out', 'back', accommodation, [source], accommodationPayload.warnings);
  assert.deepEqual(plan.warnings, {
    journeys: ['Données démo.'], outbound: ['Catalogue aller partiel.'],
    inbound: ['Catalogue retour partiel.'], accommodation: ['Aucune disponibilité annoncée.'],
  });
  const memory = new Map();
  const storage = { setItem: (key, value) => memory.set(key, value), getItem: key => memory.get(key) ?? null };
  assert.equal(saveTripPlan(storage, plan, new Date('2026-09-22T10:00:00.000Z')).ok, true);
  assert.deepEqual(loadTripPlan(storage).plan.warnings, plan.warnings);
});

test('snapshot validation is recursively exact and rejects adversarial shapes without throwing', () => {
  const plan = { ...createTripPlan(journeys, 'out', 'back', accommodation, [source], accommodationPayload.warnings), schemaVersion: 2, savedAt: '2026-09-22T10:00:00.000Z' };
  const mutations = [
    value => { value.request.garbage = true; },
    value => { value.request.departureDate = '2027-02-30'; },
    value => { value.request.modes = ['train', 'train']; },
    value => { value.outbound.legs[0].mode = 'teleport'; },
    value => { value.outbound.legs[0].distance.garbage = true; },
    value => { value.outbound.legs[0].schedule.departureAt = 'yesterday'; },
    value => { value.outbound.provenance.sourceIds = ['UPPERCASE']; },
    value => { value.outbound.emissions.factors = [{ id: 'factor', value: -1 }]; },
    value => { value.accommodation.features.garbage = true; },
    value => { value.accommodation.price.currency = 'EURO'; },
    value => { value.accommodation.evidence[0].garbage = true; },
    value => { value.sources[0].garbage = true; },
    value => { value.warnings.outbound = [42]; },
    value => { value.outbound.durationMinutes = 999; },
    value => { value.outbound.legs[0].destinationId = 'demo-macon'; },
  ];
  for (const mutate of mutations) {
    const hostile = structuredClone(plan); mutate(hostile);
    assert.doesNotThrow(() => restoreTripPlan(JSON.stringify(hostile)));
    assert.notEqual(restoreTripPlan(JSON.stringify(hostile)).ok, true);
  }
  assert.equal(restoreTripPlan(JSON.stringify({ ...plan, sources: Array(101).fill(source) })).reason, 'invalid');
  assert.equal(restoreTripPlan(' '.repeat(1_100_000)).ok, false);
});

test('verified evidence obeys organization, HTTPS reference, checked date and validity rules', () => {
  const verified = { ...accommodation.evidence[0], kind: 'certification', status: 'verified', organization: 'Label', referenceUrl: 'https://example.test/proof', checkedAt: '2026-09-01', validFrom: '2026-01-01', validUntil: '2027-01-01' };
  assert.doesNotThrow(() => normalizeAccommodationResponse({ ...accommodationPayload, items: [{ ...accommodation, evidence: [verified] }] }));
  for (const change of [
    { organization: null }, { referenceUrl: 'http://example.test/proof' }, { checkedAt: null },
    { kind: 'declaration' }, { validFrom: '2028-01-01' },
  ]) assert.throws(() => normalizeAccommodationResponse({ ...accommodationPayload, items: [{ ...accommodation, evidence: [{ ...verified, ...change }] }] }), /incomplète/i);
});

test('a real legacy v1 snapshot without plan warnings is strictly validated then migrated in memory', () => {
  const current = createTripPlan(journeys, 'out', 'back', accommodation, [source]);
  const { warnings: omitted, ...legacyPlan } = current;
  assert.ok(omitted);
  const legacy = { ...legacyPlan, schemaVersion: 1, savedAt: '2026-09-22T10:00:00Z' };
  const restored = restoreTripPlan(JSON.stringify(legacy));
  assert.equal(restored.ok, true);
  assert.equal(restored.migratedFrom, 1);
  assert.equal(restored.plan.schemaVersion, 2);
  assert.deepEqual(restored.plan.warnings, { journeys: [], outbound: [], inbound: [], accommodation: [] });

  const memory = new Map([[TRIP_PLAN_LEGACY_STORAGE_KEY, JSON.stringify(legacy)]]);
  const storage = { getItem: key => memory.get(key) ?? null };
  assert.equal(loadTripPlan(storage).migratedFrom, 1);
  assert.equal(memory.has(TRIP_PLAN_STORAGE_KEY), false, 'loading must not save a migrated snapshot automatically');

  assert.equal(restoreTripPlan(JSON.stringify({ ...legacy, warnings: current.warnings })).reason, 'invalid');
  assert.equal(restoreTripPlan(JSON.stringify({ ...legacy, outbound: { id: 'incomplete' } })).reason, 'invalid');
});

test('v2 requires warnings and takes priority without unsafe fallback to legacy v1', () => {
  const plan = createTripPlan(journeys, 'out', null, null, [source]);
  const v2 = { ...plan, schemaVersion: 2, savedAt: '2026-09-22T10:00:00+02:00' };
  const { warnings: omitted, ...legacyPlan } = plan;
  assert.ok(omitted);
  const legacy = { ...legacyPlan, schemaVersion: 1, savedAt: '2026-09-22T10:00:00Z' };
  const memory = new Map([
    [TRIP_PLAN_STORAGE_KEY, JSON.stringify(v2)],
    [TRIP_PLAN_LEGACY_STORAGE_KEY, JSON.stringify(legacy)],
  ]);
  const storage = { getItem: key => memory.get(key) ?? null };
  assert.equal(loadTripPlan(storage).migratedFrom, undefined);
  const { warnings: missing, ...v2WithoutWarnings } = v2;
  assert.ok(missing);
  memory.set(TRIP_PLAN_STORAGE_KEY, JSON.stringify(v2WithoutWarnings));
  assert.equal(loadTripPlan(storage).reason, 'invalid');
  memory.set(TRIP_PLAN_STORAGE_KEY, JSON.stringify({ ...v2, schemaVersion: 99 }));
  assert.equal(loadTripPlan(storage).reason, 'unknown_version');
});

test('clear attempts both v2 and legacy keys even when storage refuses one removal', () => {
  const removed = [];
  const storage = { removeItem: key => { removed.push(key); if (key === TRIP_PLAN_STORAGE_KEY) throw new Error('denied'); } };
  assert.equal(clearTripPlan(storage).reason, 'unavailable');
  assert.deepEqual(removed, [TRIP_PLAN_STORAGE_KEY, TRIP_PLAN_LEGACY_STORAGE_KEY]);
});

test('savedAt, provenance and schedules require a complete, real RFC3339 date-time', () => {
  const base = { ...createTripPlan(journeys, 'out', null, null, [source]), schemaVersion: 2, savedAt: '2026-09-22T10:00:00Z' };
  const validateAt = (path, value) => {
    const candidate = structuredClone(base);
    if (path === 'savedAt') candidate.savedAt = value;
    if (path === 'provenance') candidate.outbound.provenance.asOf = value;
    if (path === 'schedule') {
      candidate.outbound.legs[0].schedule.departureAt = value;
      candidate.outbound.legs[0].schedule.arrivalAt = value;
    }
    return restoreTripPlan(JSON.stringify(candidate));
  };
  for (const valid of ['2026-09-22T10:00:00Z', '2026-09-22T10:00:00.123Z', '2026-09-22T10:00:00+02:30', '2024-02-29T23:59:59-05:00']) {
    for (const path of ['savedAt', 'provenance', 'schedule']) assert.equal(validateAt(path, valid).ok, true, `${path}: ${valid}`);
  }
  for (const invalid of [
    '2026-09-22T10:00Z', '2026-09-22T10:00:00', '2026-02-29T10:00:00Z',
    '2026-13-01T10:00:00Z', '2026-09-22T24:00:00Z', '2026-09-22T10:60:00Z',
    '2026-09-22T10:00:60Z', '2026-09-22T10:00:00+24:00', '2026-09-22 10:00:00Z',
  ]) {
    for (const path of ['savedAt', 'provenance', 'schedule']) assert.equal(validateAt(path, invalid).reason, 'invalid', `${path}: ${invalid}`);
  }
});
