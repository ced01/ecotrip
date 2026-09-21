import test from 'node:test';
import assert from 'node:assert/strict';
import { DemoJourneyAdapter, SearchFlow, validateSearch } from '../../assets/search/search-core.mjs';

const valid = {
  originId: 'demo-paris',
  destinationId: 'demo-lyon',
  departureDate: '2027-01-15',
  returnDate: '',
  travelers: 1,
  modes: ['train', 'coach'],
};

const fixture = {
  dataMode: 'demo',
  request: { ...valid, returnDate: null },
  outbound: {
    status: 'complete',
    itineraries: [
      { id: 'demo-train', dataStatus: 'demo', durationMinutes: 150, transfers: 0, legs: [{ mode: 'train' }] },
      { id: 'demo-coach', dataStatus: 'demo', durationMinutes: 390, transfers: 0, legs: [{ mode: 'coach' }] },
    ],
  },
};

test('validation follows the TripRequest date, traveler and mode constraints', () => {
  assert.deepEqual(validateSearch(valid, '2026-09-21'), {});
  assert.deepEqual(validateSearch({ ...valid, destinationId: valid.originId }, '2026-09-21'), {
    destinationId: 'La destination doit être différente du départ.',
  });
  assert.deepEqual(validateSearch({ ...valid, departureDate: '2026-09-20', returnDate: '2026-09-19', travelers: 10, modes: [] }, '2026-09-21'), {
    departureDate: 'La date de départ ne peut pas être passée.',
    returnDate: 'La date de retour doit suivre le départ.',
    travelers: 'Choisissez entre 1 et 9 voyageurs.',
    modes: 'Choisissez au moins un mode de transport.',
  });
  assert.deepEqual(validateSearch({ ...valid, departureDate: '2027-02-30', returnDate: 'not-a-date' }, '2026-09-21'), {
    departureDate: 'Choisissez une date de départ valide.',
    returnDate: 'Choisissez une date de retour valide.',
  });
});

test('the demo adapter consumes the contractual shape and returns success or empty without HTTP', async () => {
  const adapter = new DemoJourneyAdapter(fixture, { delay: () => Promise.resolve() });
  const found = await adapter.search({ ...valid, modes: ['train'] });
  assert.equal(found.kind, 'success');
  assert.deepEqual(found.itineraries.map(({ id }) => id), ['demo-train']);
  assert.equal(found.dataMode, 'demo');

  const empty = await adapter.search({ ...valid, originId: 'demo-lyon', destinationId: 'demo-paris' });
  assert.deepEqual(empty, { kind: 'empty', itineraries: [], dataMode: 'demo' });
});

test('the flow exposes loading and error states', async () => {
  const states = [];
  const flow = new SearchFlow({ search: async () => { throw new Error('fixture unavailable'); } }, state => states.push(state));

  await flow.search(valid);

  assert.equal(states[0].kind, 'loading');
  assert.deepEqual(states[1], { kind: 'error', message: 'Impossible de charger la démonstration. Réessayez.' });
});

test('an obsolete response can never replace the latest search', async () => {
  const pending = [];
  const adapter = { search: request => new Promise(resolve => pending.push({ request, resolve })) };
  const states = [];
  const flow = new SearchFlow(adapter, state => states.push(state));

  const first = flow.search({ ...valid, modes: ['train'] });
  const second = flow.search({ ...valid, modes: ['coach'] });
  pending[1].resolve({ kind: 'success', itineraries: [{ id: 'new' }], dataMode: 'demo' });
  await second;
  pending[0].resolve({ kind: 'success', itineraries: [{ id: 'obsolete' }], dataMode: 'demo' });
  await first;

  assert.equal(states.at(-1).itineraries[0].id, 'new');
  assert.equal(states.some(state => state.itineraries?.[0]?.id === 'obsolete'), false);
});
