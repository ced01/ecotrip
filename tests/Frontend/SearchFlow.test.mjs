import test from 'node:test';
import assert from 'node:assert/strict';
import {
  HttpJourneyAdapter,
  SearchFlow,
  carbonSortEligibility,
  normalizeJourneyResponse,
  sortItineraries,
  validateSearch,
} from '../../assets/search/search-core.mjs';

const valid = {
  originId: 'demo-paris', destinationId: 'demo-lyon', departureDate: '2027-01-15',
  returnDate: '', travelers: 2, modes: ['train', 'coach'],
};

const itinerary = (id, durationMinutes, emissions = {}) => ({
  id, direction: 'outbound', requestedDate: '2027-01-15', dataStatus: 'demo',
  durationMinutes, transfers: 0,
  legs: [{ id: `${id}-leg`, mode: 'train', originId: 'demo-paris', destinationId: 'demo-lyon', durationMinutes, waitingMinutes: 0, distance: { km: 100, method: 'scenario' } }],
  emissions: { status: 'demo', kgCO2ePerTraveler: 2, kgCO2eGroup: 4, coveredDistanceKm: 100, totalDistanceKm: 100, comparable: true, comparisonKey: 'same', methodologyVersion: 'v1', factors: [], assumptions: [], ...emissions },
  provenance: { status: 'demo', sourceIds: ['source-a'], note: 'Synthétique' }, warnings: [],
});

const response = (outbound = {}) => ({
  dataMode: 'demo', request: { ...valid, returnDate: null },
  outbound: { status: 'complete', itineraries: [itinerary('train', 120)], warnings: [], ...outbound },
  inbound: { status: 'not_requested', itineraries: [], warnings: [] },
  sources: [{ id: 'source-a', publisher: 'Source A', url: null, license: null, version: '1', dataStatus: 'demo', reuseNotes: 'Test' }],
  warnings: ['Données de démonstration.'],
});

test('validation follows the TripRequest date, traveler and mode constraints', () => {
  assert.deepEqual(validateSearch(valid, '2026-09-21'), {});
  assert.deepEqual(validateSearch({ ...valid, destinationId: valid.originId }, '2026-09-21'), { destinationId: 'La destination doit être différente du départ.' });
  assert.deepEqual(validateSearch({ ...valid, departureDate: '2026-09-20', returnDate: '2026-09-19', travelers: 10, modes: [] }, '2026-09-21'), {
    departureDate: 'La date de départ ne peut pas être passée.', returnDate: 'La date de retour doit suivre le départ.',
    travelers: 'Choisissez entre 1 et 9 voyageurs.', modes: 'Choisissez au moins un mode de transport.',
  });
});

test('HTTP adapter posts the exact API request and normalizes every direction', async () => {
  let call;
  const adapter = new HttpJourneyAdapter('/api/v1/journeys/search', async (url, options) => {
    call = { url, options };
    return { ok: true, headers: { get: () => 'application/json' }, json: async () => response() };
  });
  const result = await adapter.search(valid);
  assert.equal(call.url, '/api/v1/journeys/search');
  assert.equal(call.options.method, 'POST');
  assert.equal(call.options.headers.Accept, 'application/json, application/problem+json');
  assert.deepEqual(JSON.parse(call.options.body), { ...valid, returnDate: null });
  assert.equal(result.kind, 'results');
  assert.equal(result.outbound.itineraries[0].id, 'train');
  assert.equal(result.inbound.status, 'not_requested');
});

test('HTTP adapter preserves safe Problem JSON details and status', async () => {
  const adapter = new HttpJourneyAdapter('/api/v1/journeys/search', async () => ({
    ok: false, status: 422, headers: { get: () => 'application/problem+json' },
    json: async () => ({ status: 422, code: 'validation_failed', detail: 'La requête ne peut pas être traitée.', violations: [{ field: 'modes', message: 'Mode invalide.' }] }),
  }));
  await assert.rejects(adapter.search(valid), error => {
    assert.equal(error.name, 'JourneyProblem');
    assert.equal(error.status, 422);
    assert.equal(error.code, 'validation_failed');
    assert.deepEqual(error.violations, [{ field: 'modes', message: 'Mode invalide.' }]);
    return true;
  });
});

test('normalization keeps empty, out-of-coverage, unavailable and partial states distinct', () => {
  for (const status of ['empty', 'out_of_coverage', 'unavailable', 'partial']) {
    const normalized = normalizeJourneyResponse(response({ status, itineraries: status === 'partial' ? [itinerary('partial', 90)] : [] }));
    assert.equal(normalized.outbound.status, status);
    assert.equal(normalized.kind, 'results');
  }
});

test('carbon sorting only includes complete compatible estimates and never treats unknown as zero', () => {
  const complete = itinerary('complete', 120);
  const incompatible = itinerary('incompatible', 80, { comparisonKey: 'other', kgCO2ePerTraveler: 0.1 });
  const partial = itinerary('partial', 60, { status: 'partial', comparable: true, coveredDistanceKm: 50, kgCO2ePerTraveler: 0.2 });
  const unavailable = itinerary('unknown', 40, { status: 'unavailable', comparable: false, kgCO2ePerTraveler: null, kgCO2eGroup: null });
  assert.deepEqual(carbonSortEligibility([complete, incompatible, partial, unavailable]), { eligibleIds: ['complete'], excludedIds: ['incompatible', 'partial', 'unknown'], comparisonKey: 'same' });
  assert.deepEqual(sortItineraries([unavailable, complete], 'carbon').map(({ id }) => id), ['complete', 'unknown']);
  assert.deepEqual(sortItineraries([complete, unavailable], 'duration').map(({ id }) => id), ['unknown', 'complete']);
});

test('a new search aborts and neutralizes the obsolete HTTP response', async () => {
  const pending = [];
  const adapter = { search: (request, { signal }) => new Promise(resolve => pending.push({ request, signal, resolve })) };
  const states = [];
  const flow = new SearchFlow(adapter, state => states.push(state));
  const first = flow.search({ ...valid, modes: ['train'] });
  const second = flow.search({ ...valid, modes: ['coach'] });
  assert.equal(pending[0].signal.aborted, true);
  pending[1].resolve(normalizeJourneyResponse(response())); await second;
  pending[0].resolve(normalizeJourneyResponse(response({ itineraries: [itinerary('obsolete', 1)] }))); await first;
  assert.equal(states.at(-1).outbound.itineraries[0].id, 'train');
  assert.equal(states.some(state => state.outbound?.itineraries?.[0]?.id === 'obsolete'), false);
});

test('the flow exposes loading, Problem JSON and network failure states', async () => {
  for (const [error, expected] of [
    [Object.assign(new Error('problem'), { name: 'JourneyProblem', status: 503, code: 'provider_unavailable', detail: 'Service temporairement indisponible.', violations: [] }), 'problem'],
    [new Error('network secret'), 'network_error'],
  ]) {
    const states = [];
    await new SearchFlow({ search: async () => { throw error; } }, state => states.push(state)).search(valid);
    assert.equal(states[0].kind, 'loading');
    assert.equal(states[1].kind, expected);
    assert.equal(JSON.stringify(states[1]).includes('network secret'), false);
  }
});