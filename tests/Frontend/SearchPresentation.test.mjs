import test from 'node:test';
import assert from 'node:assert/strict';
import {
  formatCarbonFactor,
  formatProvenance,
  safeHttpUrl,
} from '../../assets/search/search-presentation.mjs';

test('source links only accept absolute HTTP(S) URLs', () => {
  assert.equal(safeHttpUrl('https://data.example/source?q=1'), 'https://data.example/source?q=1');
  assert.equal(safeHttpUrl('http://data.example/source'), 'http://data.example/source');

  for (const hostile of [
    'javascript:alert(1)',
    'data:text/html,payload',
    'file:///etc/passwd',
    'ftp://data.example/source',
    'https://',
    '//data.example/source',
    '/relative/source',
    'not a URL',
    '',
    null,
  ]) {
    assert.equal(safeHttpUrl(hostile), null, String(hostile));
  }
});

test('provenance formatting exposes present fields without inventing missing values', () => {
  assert.equal(
    formatProvenance({ status: 'demo', sourceIds: ['journey-source'], asOf: '2026-04-01', note: 'Scénario.' }),
    'statut : demo · sources : journey-source · au : 2026-04-01 · note : Scénario.',
  );
  assert.equal(
    formatProvenance({ status: 'estimated', sourceIds: [] }),
    'statut : estimated · sources : non renseignées · au : non renseigné',
  );
  assert.equal(
    formatProvenance(null),
    'statut : Inconnu · sources : non renseignées · au : non renseigné',
  );
});

test('carbon factor formatting links value and unit to mode and global source', () => {
  assert.equal(
    formatCarbonFactor({ id: 'train-v1', value: 0.02, unit: 'kgCO2e/passenger-km', mode: 'train', sourceId: 'source-a' }),
    'train-v1 — 0,02 kgCO2e/passenger-km · mode : train · source : source-a',
  );
  assert.equal(
    formatCarbonFactor({}),
    'Facteur inconnu — valeur non renseignée · mode : Inconnu · source : non renseignée',
  );
});
