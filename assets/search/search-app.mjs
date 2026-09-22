import { HttpJourneyAdapter, SearchFlow, carbonSortEligibility, sortItineraries, validateSearch } from 'ecotrip/search-core';

const form = document.querySelector('[data-search-form]');
if (form) initialise(form);

function initialise(form) {
  const status = document.querySelector('[data-status]');
  const errorState = document.querySelector('[data-error-state]');
  const results = document.querySelector('[data-results]');
  const title = document.querySelector('#results-title');
  const toolbar = document.querySelector('[data-toolbar]');
  const sort = document.querySelector('[data-sort]');
  const sortExplanation = document.querySelector('[data-sort-explanation]');
  const methodology = document.querySelector('[data-methodology]');
  const button = form.querySelector('button[type="submit"]');
  let latest = null;

  const localToday = () => {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
  };
  form.elements.departureDate.min = localToday();

  const clearFeedback = () => {
    form.querySelectorAll('[aria-invalid="true"]').forEach(field => field.removeAttribute('aria-invalid'));
    form.querySelectorAll('[data-error-for]').forEach(node => { node.textContent = ''; });
    errorState.hidden = true;
    errorState.replaceChildren();
  };

  const render = state => {
    button.disabled = state.kind === 'loading';
    form.setAttribute('aria-busy', state.kind === 'loading' ? 'true' : 'false');
    results.replaceChildren();
    errorState.hidden = true;
    toolbar.hidden = true;
    methodology.hidden = true;

    if (state.kind === 'loading') {
      status.textContent = 'Recherche auprès de l’API Ecotrip…';
      results.append(createSkeleton(), createSkeleton());
      return;
    }
    if (state.kind === 'problem' || state.kind === 'network_error') {
      latest = null;
      status.textContent = '';
      renderError(errorState, state);
      return;
    }

    latest = state;
    toolbar.hidden = false;
    methodology.hidden = false;
    renderMethodology(methodology, state);
    renderDirections(results, state, sort.value, sortExplanation);
    const count = state.outbound.itineraries.length + state.inbound.itineraries.length;
    status.textContent = `${count} itinéraire${count > 1 ? 's' : ''} reçu${count > 1 ? 's' : ''}. Aller et retour sont présentés séparément.`;
    title.focus({ preventScroll: true });
  };

  const flow = new SearchFlow(new HttpJourneyAdapter(form.dataset.endpoint), render);

  sort.addEventListener('change', () => {
    if (!latest) return;
    results.replaceChildren();
    renderDirections(results, latest, sort.value, sortExplanation);
    status.textContent = sort.value === 'api' ? 'Ordre fourni par l’API appliqué.' : `Tri explicite « ${sort.selectedOptions[0].textContent} » appliqué.`;
  });

  form.addEventListener('submit', event => {
    event.preventDefault();
    clearFeedback();
    const data = new FormData(form);
    const request = {
      originId: data.get('originId'), destinationId: data.get('destinationId'),
      departureDate: data.get('departureDate'), returnDate: data.get('returnDate'),
      travelers: Number(data.get('travelers')), modes: data.getAll('modes[]'),
    };
    const errors = validateSearch(request, localToday());
    if (Object.keys(errors).length) {
      for (const [name, message] of Object.entries(errors)) markInvalid(form, name, message);
      status.textContent = 'Le formulaire contient des erreurs. Vérifiez les champs signalés.';
      form.querySelector('[aria-invalid="true"]')?.focus();
      return;
    }
    flow.search(request);
  });
}

function markInvalid(form, name, message) {
  const target = form.querySelector(`[data-error-for="${name}"]`);
  if (target) target.textContent = message;
  const field = name === 'modes' ? form.querySelector('input[name="modes[]"]') : form.elements[name];
  field?.setAttribute('aria-invalid', 'true');
}

function renderError(container, state) {
  container.hidden = false;
  const heading = element('h3', state.kind === 'network_error' ? 'Service injoignable' : problemTitle(state));
  const message = element('p', state.message ?? state.detail ?? 'La recherche n’a pas pu aboutir.');
  container.append(heading, message);
  if (state.violations?.length) {
    const list = document.createElement('ul');
    state.violations.forEach(violation => list.append(element('li', violation.message ?? 'Valeur non valide.')));
    container.append(list);
  }
}

function problemTitle(state) {
  if (state.status === 503 || state.code === 'provider_unavailable') return 'Fournisseur temporairement indisponible';
  if (state.status === 429) return 'Trop de recherches rapprochées';
  if (state.status === 422) return 'Recherche non valide';
  return 'La recherche a échoué';
}

function renderDirections(container, state, criterion, explanation) {
  const directions = [['outbound', 'Aller'], ['inbound', 'Retour']];
  let excluded = 0;
  for (const [key, label] of directions) {
    const direction = state[key];
    if (direction.status === 'not_requested') continue;
    const section = document.createElement('section');
    section.className = 'direction-results';
    section.setAttribute('aria-labelledby', `${key}-title`);
    const heading = element('h3', `${label} · ${directionStatus(direction.status)}`);
    heading.id = `${key}-title`;
    section.append(heading);
    direction.warnings.forEach(warning => section.append(warningNode(warning)));
    if (!direction.itineraries.length) section.append(element('p', directionEmptyMessage(direction.status)));
    const eligibility = carbonSortEligibility(direction.itineraries);
    if (criterion === 'carbon') excluded += eligibility.excludedIds.length;
    sortItineraries(direction.itineraries, criterion).forEach(itinerary => section.append(createResultCard(itinerary, state.request?.travelers, criterion === 'carbon' && eligibility.excludedIds.includes(itinerary.id))));
    container.append(section);
  }
  explanation.textContent = criterion === 'carbon'
    ? `Tri carbone par kgCO₂e par voyageur, à méthodologie compatible. ${excluded} estimation${excluded > 1 ? 's' : ''} exclue${excluded > 1 ? 's' : ''} du tri et conservée${excluded > 1 ? 's' : ''} après les valeurs comparables.`
    : 'Chaque critère est indépendant : aucun score caché n’est calculé.';
}

function directionStatus(status) {
  return ({ complete: 'résultats complets', partial: 'résultats partiels', empty: 'aucun itinéraire', out_of_coverage: 'hors couverture', unavailable: 'indisponible' })[status] ?? status;
}

function directionEmptyMessage(status) {
  return ({ empty: 'Aucun itinéraire ne correspond aux modes et à la date demandés.', out_of_coverage: 'Ce trajet se trouve hors de la couverture du fournisseur.', unavailable: 'Cette direction est temporairement indisponible.' })[status] ?? 'Aucun résultat disponible.';
}

function createSkeleton() {
  const node = document.createElement('div');
  node.className = 'result-card skeleton';
  node.setAttribute('aria-hidden', 'true');
  return node;
}

function createResultCard(itinerary, travelers, carbonExcluded) {
  const card = document.createElement('article');
  card.className = 'result-card';
  card.append(element('h4', itineraryName(itinerary)));
  const summary = document.createElement('dl');
  summary.className = 'result-summary';
  addDefinition(summary, 'Durée totale', formatDuration(itinerary.durationMinutes));
  addDefinition(summary, 'Correspondances', knownNumber(itinerary.transfers, value => String(value)));
  addDefinition(summary, 'Distance totale', formatDistance(itinerary.emissions?.totalDistanceKm ?? sumKnownDistances(itinerary.legs)));
  addDefinition(summary, 'Par voyageur', formatEmission(itinerary.emissions?.kgCO2ePerTraveler));
  addDefinition(summary, `Groupe (${Number.isInteger(travelers) ? travelers : '?'} pers.)`, formatEmission(itinerary.emissions?.kgCO2eGroup));
  card.append(summary);

  const coverage = emissionsCoverage(itinerary.emissions);
  const evidence = element('p', coverage);
  evidence.className = 'data-quality';
  card.append(evidence);
  if (carbonExcluded) card.append(warningNode('Cette estimation est exclue du tri carbone : données incomplètes, indisponibles ou méthodologie incompatible.'));

  const details = document.createElement('details');
  details.className = 'itinerary-details';
  details.append(element('summary', 'Afficher les étapes et les preuves'));
  const legs = document.createElement('ol');
  legs.className = 'legs';
  (itinerary.legs ?? []).forEach((leg, index) => {
    const item = document.createElement('li');
    item.append(element('strong', `Étape ${index + 1} · ${modeName(leg.mode)}`));
    item.append(element('span', `${leg.originId ?? 'origine inconnue'} → ${leg.destinationId ?? 'destination inconnue'}`));
    item.append(element('span', `Trajet : ${formatDuration(leg.durationMinutes)} · attente : ${formatDuration(leg.waitingMinutes)} · distance : ${formatDistance(leg.distance?.km)}`));
    item.append(element('small', `Méthode distance : ${leg.distance?.method ?? 'inconnue'}${leg.provenance?.note ? ` · ${leg.provenance.note}` : ''}`));
    legs.append(item);
  });
  details.append(legs);
  details.append(element('p', `Version de méthode carbone : ${itinerary.emissions?.methodologyVersion ?? 'inconnue'}. Clé de comparaison : ${itinerary.emissions?.comparisonKey ?? 'indisponible'}.`));
  (itinerary.emissions?.assumptions ?? []).forEach(value => details.append(warningNode(`Hypothèse : ${value}`)));
  (itinerary.warnings ?? []).forEach(value => details.append(warningNode(value)));
  card.append(details);

  const badge = element('span', itinerary.dataStatus === 'demo' ? 'Donnée synthétique — non réservable' : `Statut : ${itinerary.dataStatus ?? 'inconnu'}`);
  badge.className = 'result-badge';
  card.append(badge);
  return card;
}

function addDefinition(list, term, value) { list.append(element('dt', term), element('dd', value)); }
function knownNumber(value, formatter) { return Number.isFinite(value) ? formatter(value) : 'Inconnu'; }
function formatDuration(value) { return knownNumber(value, minutes => `${Math.floor(minutes / 60)} h ${String(minutes % 60).padStart(2, '0')}`); }
function formatDistance(value) { return knownNumber(value, km => `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 }).format(km)} km`); }
function formatEmission(value) { return knownNumber(value, kg => `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 }).format(kg)} kgCO₂e`); }
function sumKnownDistances(legs = []) { const values = legs.map(leg => leg.distance?.km); return values.length && values.every(Number.isFinite) ? values.reduce((sum, value) => sum + value, 0) : null; }

function emissionsCoverage(emissions) {
  if (!emissions || !Number.isFinite(emissions.kgCO2ePerTraveler)) return 'Émissions indisponibles — aucune valeur zéro n’est supposée.';
  if (!Number.isFinite(emissions.coveredDistanceKm) || !Number.isFinite(emissions.totalDistanceKm)) return 'Couverture de l’estimation inconnue.';
  const complete = emissions.coveredDistanceKm === emissions.totalDistanceKm;
  return `${complete ? 'Couverture complète' : 'Couverture partielle'} : ${formatDistance(emissions.coveredDistanceKm)} estimés sur ${formatDistance(emissions.totalDistanceKm)}. ${emissions.comparable ? 'Déclarée comparable.' : 'Non comparable.'}`;
}

function itineraryName(itinerary) {
  const modes = [...new Set((itinerary.legs ?? []).map(leg => modeName(leg.mode)))];
  return modes.length ? modes.join(' + ') : 'Mode inconnu';
}
function modeName(mode) { return ({ train: 'Train', coach: 'Car', walk: 'Marche', public_transport: 'Transports publics', bicycle: 'Vélo', carpool: 'Covoiturage', flight: 'Avion' })[mode] ?? (mode || 'Mode inconnu'); }

function renderMethodology(container, state) {
  const warnings = container.querySelector('[data-global-warnings]');
  const sources = container.querySelector('[data-sources]');
  warnings.replaceChildren(); sources.replaceChildren();
  state.warnings.forEach(value => warnings.append(warningNode(value)));
  if (!state.sources.length) sources.append(element('li', 'Source non renseignée.'));
  state.sources.forEach(source => {
    const item = document.createElement('li');
    const label = `${source.publisher ?? source.id} — version ${source.version ?? 'inconnue'} — statut ${source.dataStatus ?? 'inconnu'}`;
    if (source.url) { const link = element('a', label); link.href = source.url; item.append(link); } else item.append(document.createTextNode(label));
    if (source.reuseNotes) item.append(element('small', source.reuseNotes));
    sources.append(item);
  });
}

function warningNode(message) { const node = element('p', message); node.className = 'warning'; return node; }
function element(tag, text) { const node = document.createElement(tag); node.textContent = text; return node; }
