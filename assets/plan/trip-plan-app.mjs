import {
  AccommodationFlow,
  HttpAccommodationAdapter,
  accommodationQueryKey,
  clearTripPlan,
  createTripPlan,
  formatSavedAge,
  loadTripPlan,
  reconcileAccommodationSelection,
  saveTripPlan,
} from 'ecotrip/trip-plan-core';
import { formatProvenance, safeHttpUrl } from 'ecotrip/search-presentation';

const section = document.querySelector('[data-accommodations]');
const planSection = document.querySelector('[data-trip-plan]');
if (section && planSection) initialise(section, planSection);

function initialise(accommodationSection, planSection) {
  const filterForm = accommodationSection.querySelector('[data-accommodation-filters]');
  const accommodationStatus = accommodationSection.querySelector('[data-accommodation-status]');
  const accommodationError = accommodationSection.querySelector('[data-accommodation-error]');
  const accommodationList = accommodationSection.querySelector('[data-accommodation-list]');
  const accommodationMethodology = accommodationSection.querySelector('[data-accommodation-methodology]');
  const planStatus = planSection.querySelector('[data-plan-status]');
  const planSummary = planSection.querySelector('[data-plan-summary]');
  const savedMeta = planSection.querySelector('[data-saved-meta]');
  const saveButton = planSection.querySelector('[data-plan-save]');
  const restoreButton = planSection.querySelector('[data-plan-restore]');
  const clearButton = planSection.querySelector('[data-plan-clear]');
  const printButton = planSection.querySelector('[data-plan-print]');
  const storage = browserStorage();
  let journeys = null;
  let accommodationResponse = null;
  let accommodationSelection = null;
  let outboundId = null;
  let inboundId = null;
  let currentPlan = null;
  let destinationId = null;

  const query = () => ({
    destinationId,
    bicycleParking: filterForm.elements.bicycleParking.value,
    publicTransportNearby: filterForm.elements.publicTransportNearby.value,
    limit: 20,
  });

  const renderAccommodationState = state => {
    accommodationList.replaceChildren();
    accommodationError.hidden = true;
    accommodationError.replaceChildren();
    accommodationMethodology.hidden = true;
    filterForm.setAttribute('aria-busy', state.kind === 'loading' ? 'true' : 'false');
    filterForm.querySelector('button').disabled = state.kind === 'loading';
    if (state.kind === 'loading') {
      accommodationStatus.textContent = 'Chargement des hébergements auprès de l’API Ecotrip…';
      saveButton.disabled = true; printButton.disabled = true;
      accommodationList.append(skeleton(), skeleton());
      return;
    }
    if (state.kind === 'problem' || state.kind === 'network_error') {
      accommodationResponse = null;
      accommodationStatus.textContent = '';
      accommodationError.hidden = false;
      accommodationError.append(
        element('h3', state.kind === 'network_error' ? 'Catalogue injoignable' : problemTitle(state)),
        element('p', state.message ?? state.detail ?? 'Le catalogue n’a pas pu être chargé.'),
      );
      (state.violations ?? []).forEach(violation => accommodationError.append(element('p', violation.message ?? 'Paramètre non valide.')));
      invalidateAccommodation([], query());
      return;
    }
    accommodationResponse = state;
    const reconciled = reconcileAccommodationSelection(accommodationSelection, query(), state.items);
    accommodationSelection = reconciled.selection;
    if (reconciled.reason) announceInvalidation(reconciled.reason);
    renderAccommodationCards(state.items);
    renderAccommodationSources(state);
    accommodationMethodology.hidden = false;
    accommodationStatus.textContent = accommodationMessage(state);
    updatePlan();
  };

  const accommodationFlow = new AccommodationFlow(
    new HttpAccommodationAdapter(accommodationSection.dataset.endpoint),
    renderAccommodationState,
  );

  function renderAccommodationCards(items) {
    const noStay = element('button', accommodationSelection ? 'Ne pas ajouter d’hébergement' : 'Continuer sans hébergement');
    noStay.type = 'button';
    noStay.className = 'secondary-button no-stay';
    noStay.addEventListener('click', () => {
      accommodationSelection = null;
      accommodationList.replaceChildren();
      renderAccommodationCards(items);
      planStatus.textContent = 'Aucun hébergement ajouté au plan.';
      updatePlan();
    });
    accommodationList.append(noStay);
    for (const item of items) accommodationList.append(accommodationCard(item));
  }

  function accommodationCard(item) {
    const card = document.createElement('article');
    card.className = 'accommodation-card';
    card.append(element('h3', item.name));
    const facts = document.createElement('dl');
    facts.className = 'result-summary';
    addDefinition(facts, 'Stationnement vélo', triState(item.features?.bicycleParking));
    addDefinition(facts, 'Transports à proximité', triState(item.features?.publicTransportNearby));
    addDefinition(facts, 'Distance transport', Number.isInteger(item.publicTransportDistanceMeters) ? `${item.publicTransportDistanceMeters} m` : 'Inconnue');
    addDefinition(facts, 'Prix indicatif', formatPrice(item.price));
    card.append(facts, element('p', `Provenance : ${formatProvenance(item.provenance)}.`));
    const evidence = document.createElement('details');
    evidence.append(element('summary', 'Déclarations et preuves'));
    if (!item.evidence.length) evidence.append(element('p', 'Aucune déclaration ou preuve renseignée.'));
    const list = document.createElement('ul');
    for (const proof of item.evidence) {
      const row = document.createElement('li');
      row.append(document.createTextNode(`${proof.kind === 'declaration' ? 'Déclaration' : 'Certification'} — ${proof.claim} · statut : ${proof.status} · source : ${proof.sourceId}`));
      if (proof.organization) row.append(document.createTextNode(` · organisme : ${proof.organization}`));
      if (proof.validUntil) row.append(document.createTextNode(` · valide jusqu’au : ${proof.validUntil}`));
      const href = safeHttpUrl(proof.referenceUrl);
      if (href) { row.append(document.createTextNode(' · ')); const link = element('a', 'référence fournisseur'); link.href = href; link.rel = 'noopener noreferrer'; row.append(link); }
      list.append(row);
    }
    evidence.append(list);
    card.append(evidence);
    const badge = element('span', item.dataStatus === 'demo' ? 'Donnée synthétique — disponibilité inconnue' : `Statut : ${item.dataStatus}`);
    badge.className = 'result-badge';
    card.append(badge);
    const choose = element('button', accommodationSelection?.item.id === item.id ? 'Hébergement ajouté au plan' : 'Ajouter cet hébergement');
    choose.type = 'button'; choose.className = 'plan-choice';
    choose.setAttribute('aria-pressed', accommodationSelection?.item.id === item.id ? 'true' : 'false');
    choose.addEventListener('click', () => {
      accommodationSelection = { item, destinationId, queryKey: accommodationQueryKey(query()) };
      renderAccommodationCardsFresh();
      planStatus.textContent = `${item.name} ajouté au plan. Aucune disponibilité ni réservation n’est supposée.`;
      updatePlan();
    });
    card.append(choose);
    return card;
  }

  function renderAccommodationCardsFresh() {
    accommodationList.replaceChildren();
    renderAccommodationCards(accommodationResponse?.items ?? []);
  }

  function renderAccommodationSources(state) {
    const warnings = accommodationMethodology.querySelector('[data-accommodation-warnings]');
    const sources = accommodationMethodology.querySelector('[data-accommodation-sources]');
    warnings.replaceChildren(); sources.replaceChildren();
    state.warnings.forEach(value => warnings.append(warningNode(value)));
    if (!state.sources.length) sources.append(element('li', 'Source non renseignée.'));
    state.sources.forEach(source => sources.append(sourceNode(source)));
  }

  function invalidateAccommodation(items, nextQuery) {
    const reconciled = reconcileAccommodationSelection(accommodationSelection, nextQuery, items);
    accommodationSelection = reconciled.selection;
    if (reconciled.reason) announceInvalidation(reconciled.reason);
    updatePlan();
  }

  function announceInvalidation(reason) {
    planStatus.textContent = reason;
    planSection.querySelector('#trip-plan-title')?.focus({ preventScroll: true });
  }

  function updatePlan() {
    if (!journeys || !outboundId) {
      currentPlan = null;
      planSummary.replaceChildren();
      saveButton.disabled = true; printButton.disabled = true;
      return;
    }
    try {
      currentPlan = createTripPlan(
        journeys, outboundId, inboundId, accommodationSelection?.item ?? null,
        accommodationResponse?.sources ?? [], accommodationResponse?.warnings ?? [],
      );
      renderPlan(currentPlan, planSummary);
      saveButton.disabled = false; printButton.disabled = false;
    } catch {
      currentPlan = null;
      planSummary.replaceChildren();
      saveButton.disabled = true; printButton.disabled = true;
      planStatus.textContent = 'Le plan est devenu incompatible avec la recherche. Choisissez de nouveau un trajet.';
    }
  }

  document.addEventListener('ecotrip:search-start', event => {
    const changed = destinationId && destinationId !== event.detail.destinationId;
    destinationId = event.detail.destinationId;
    journeys = null; outboundId = null; inboundId = null;
    if (changed && accommodationSelection) announceInvalidation('L’hébergement a été retiré du plan car la destination a changé.');
    if (changed) accommodationSelection = null;
    accommodationResponse = null;
    accommodationSection.hidden = true;
    updatePlan();
  });

  document.addEventListener('ecotrip:journeys-loaded', event => {
    journeys = event.detail;
    destinationId = journeys.request.destinationId;
    outboundId = null; inboundId = null;
    accommodationSection.hidden = false;
    accommodationFlow.search(query());
    accommodationSection.querySelector('#accommodations-title')?.focus({ preventScroll: true });
    planStatus.textContent = 'Choisissez un trajet aller pour composer le plan. Le retour et l’hébergement sont facultatifs.';
    updatePlan();
  });

  document.addEventListener('ecotrip:itinerary-selected', event => {
    if (!journeys) return;
    if (event.detail.direction === 'outbound') outboundId = event.detail.id;
    if (event.detail.direction === 'inbound') inboundId = event.detail.id;
    planStatus.textContent = event.detail.direction === 'outbound' ? 'Trajet aller ajouté au plan.' : 'Trajet retour ajouté au plan.';
    updatePlan();
    planSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  document.querySelector('#destination')?.addEventListener('change', event => {
    if (!accommodationSelection || event.target.value === accommodationSelection.destinationId) return;
    destinationId = event.target.value;
    accommodationSelection = null;
    announceInvalidation('L’hébergement a été retiré du plan car la destination a changé. Relancez la recherche.');
    updatePlan();
  });

  filterForm.addEventListener('submit', event => {
    event.preventDefault();
    const nextQuery = query();
    if (accommodationSelection?.queryKey !== undefined && accommodationSelection.queryKey !== accommodationQueryKey(nextQuery)) {
      const reconciled = reconcileAccommodationSelection(accommodationSelection, nextQuery, accommodationResponse?.items ?? []);
      accommodationSelection = reconciled.selection;
      if (reconciled.reason) announceInvalidation(reconciled.reason);
      renderAccommodationCardsFresh();
      updatePlan();
    }
    if (destinationId) accommodationFlow.search(nextQuery);
  });

  saveButton.addEventListener('click', () => {
    const result = saveTripPlan(storage, currentPlan, new Date());
    if (!result.ok) {
      savedMeta.textContent = result.reason === 'unavailable' ? 'Sauvegarde impossible : le stockage local est indisponible ou refusé.' : 'Le plan ne peut pas être sauvegardé car il est invalide.';
      return;
    }
    savedMeta.textContent = `${formatSavedAge(result.plan.savedAt)} · ${new Date(result.plan.savedAt).toLocaleString('fr-FR')} · format v${result.plan.schemaVersion}.`;
    planStatus.textContent = 'Plan sauvegardé volontairement sur cet appareil.';
  });

  restoreButton.addEventListener('click', () => {
    const result = loadTripPlan(storage);
    if (!result.ok) {
      const messages = { absent: 'Aucune sauvegarde locale à restaurer.', unavailable: 'Restauration impossible : stockage local indisponible ou refusé.', corrupt: 'Sauvegarde illisible ou corrompue : elle n’a pas été restaurée.', unknown_version: 'Version de sauvegarde inconnue : elle n’a pas été restaurée.', invalid: 'Sauvegarde non conforme : elle n’a pas été restaurée.', incompatible: 'Sauvegarde incompatible : elle n’a pas été restaurée.' };
      planStatus.textContent = messages[result.reason] ?? 'La sauvegarde n’a pas été restaurée.';
      return;
    }
    currentPlan = result.plan;
    journeys = null; outboundId = null; inboundId = null; accommodationSelection = null;
    renderPlan(currentPlan, planSummary);
    saveButton.disabled = false; printButton.disabled = false;
    savedMeta.textContent = `${formatSavedAge(currentPlan.savedAt)} · ${new Date(currentPlan.savedAt).toLocaleString('fr-FR')} · format v${currentPlan.schemaVersion}.`;
    planStatus.textContent = result.migratedFrom === 1
      ? 'Ancien plan local v1 restauré et migré en mémoire vers le format v2. Sauvegardez-le pour conserver le nouveau format.'
      : 'Plan local v2 restauré après validation.';
    planSection.querySelector('#trip-plan-title')?.focus({ preventScroll: true });
  });

  clearButton.addEventListener('click', () => {
    const result = clearTripPlan(storage);
    savedMeta.textContent = result.ok ? 'Sauvegarde locale effacée.' : 'Effacement impossible : stockage local indisponible ou refusé.';
    planStatus.textContent = savedMeta.textContent;
  });
  printButton.addEventListener('click', () => globalThis.print());

  const existing = loadTripPlan(storage);
  if (existing.ok) savedMeta.textContent = existing.migratedFrom === 1
    ? `Une ancienne sauvegarde v1 validable est disponible (${formatSavedAge(existing.plan.savedAt)}). Elle sera migrée en mémoire vers v2 lors de la restauration.`
    : `Une sauvegarde v2 validable est disponible (${formatSavedAge(existing.plan.savedAt)}). Utilisez « Restaurer » pour l’afficher.`;
  else if (!['absent'].includes(existing.reason)) savedMeta.textContent = existing.reason === 'unavailable' ? 'Stockage local indisponible ou refusé.' : 'Une sauvegarde locale non valide est présente; elle ne sera pas restaurée.';
}

function renderPlan(plan, container) {
  container.replaceChildren();
  const overview = document.createElement('article'); overview.className = 'plan-overview';
  overview.append(element('h3', `${plan.request.originId} → ${plan.request.destinationId}`));
  overview.append(element('p', `Départ : ${plan.request.departureDate} · retour demandé : ${plan.request.returnDate || 'non'} · voyageurs : ${plan.request.travelers}.`));
  overview.append(itinerarySummary('Aller', plan.outbound));
  overview.append(plan.inbound ? itinerarySummary('Retour', plan.inbound) : element('p', 'Retour : non sélectionné.'));
  overview.append(plan.accommodation ? accommodationSummary(plan.accommodation) : element('p', 'Hébergement : non sélectionné.'));
  const sourceDetails = document.createElement('details'); sourceDetails.className = 'plan-evidence';
  sourceDetails.append(element('summary', 'Sources, statuts, hypothèses et avertissements conservés'));
  const sources = document.createElement('ul');
  if (!plan.sources.length) sources.append(element('li', 'Source globale non renseignée.'));
  plan.sources.forEach(source => sources.append(sourceNode(source)));
  sourceDetails.append(sources);
  const warningLabels = {
    journeys: 'Recherche de trajets', outbound: 'Catalogue aller',
    inbound: 'Catalogue retour', accommodation: 'Catalogue hébergements',
  };
  for (const [category, values] of Object.entries(plan.warnings ?? {})) {
    values.forEach(message => sourceDetails.append(warningNode(`${warningLabels[category] ?? category} : ${message}`)));
  }
  overview.append(sourceDetails);
  container.append(overview);
}

function itinerarySummary(label, item) {
  const section = document.createElement('section'); section.className = 'plan-part';
  section.append(element('h4', `${label} · ${item.requestedDate}`));
  section.append(element('p', `${item.durationMinutes} min · ${item.transfers} correspondance(s) · statut des données : ${item.dataStatus}.`));
  section.append(element('p', `Provenance : ${formatProvenance(item.provenance)}.`));
  const emissions = item.emissions ?? {};
  section.append(element('p', `Émissions par voyageur : ${Number.isFinite(emissions.kgCO2ePerTraveler) ? `${emissions.kgCO2ePerTraveler} kgCO₂e` : 'inconnues'} · statut : ${emissions.status ?? 'inconnu'} · ${emissions.comparable ? 'comparables selon la clé fournie' : 'non comparables'}.`));
  (emissions.assumptions ?? []).forEach(value => section.append(warningNode(`Hypothèse : ${value}`)));
  (item.warnings ?? []).forEach(value => section.append(warningNode(value)));
  return section;
}

function accommodationSummary(item) {
  const section = document.createElement('section'); section.className = 'plan-part';
  section.append(element('h4', `Hébergement · ${item.name}`));
  section.append(element('p', `Statut des données : ${item.dataStatus} · vélo : ${triState(item.features?.bicycleParking)} · transports : ${triState(item.features?.publicTransportNearby)} · prix : ${formatPrice(item.price)}.`));
  section.append(element('p', `Provenance : ${formatProvenance(item.provenance)}.`));
  if (!item.evidence.length) section.append(element('p', 'Preuve ou déclaration : non renseignée.'));
  item.evidence.forEach(proof => section.append(element('p', `${proof.kind === 'declaration' ? 'Déclaration' : 'Certification'} : ${proof.claim} · statut ${proof.status} · source ${proof.sourceId}.`)));
  section.append(warningNode('Aucune disponibilité, réservation ni émission hôtelière n’est déduite.'));
  return section;
}

function accommodationMessage(state) {
  if (state.status === 'out_of_coverage') return 'Destination hors couverture du catalogue. Vous pouvez continuer sans hébergement.';
  if (state.status === 'empty') return 'Aucun hébergement ne correspond à ces filtres. Vous pouvez continuer sans hébergement.';
  return `${state.items.length} hébergement${state.items.length > 1 ? 's' : ''} reçu${state.items.length > 1 ? 's' : ''}. La sélection reste facultative.`;
}
function problemTitle(state) { return state.status === 503 ? 'Fournisseur d’hébergements indisponible' : state.status === 422 ? 'Filtres non valides' : 'Chargement des hébergements échoué'; }
function triState(value) { return value === true ? 'Oui (caractéristique déclarée)' : value === false ? 'Non' : 'Inconnu'; }
function formatPrice(price) {
  if (!price || !Number.isFinite(price.amount)) return 'Non renseigné';
  const basis = price.basis === 'night_per_person' ? 'par nuit et par personne' : price.basis === 'night_per_room' ? 'par nuit et par chambre' : 'base inconnue';
  return `${new Intl.NumberFormat('fr-FR', { style: 'currency', currency: price.currency }).format(price.amount)} ${basis} · au ${price.asOf} · statut ${price.dataStatus} · source ${price.sourceId}`;
}
function sourceNode(source) {
  const item = document.createElement('li');
  const label = `${source.publisher ?? source.id} — version ${source.version ?? 'inconnue'} — statut ${source.dataStatus ?? 'inconnu'}`;
  const href = safeHttpUrl(source.url);
  if (href) { const link = element('a', label); link.href = href; link.rel = 'noopener noreferrer'; item.append(link); } else item.append(document.createTextNode(label));
  if (source.reuseNotes) item.append(element('small', source.reuseNotes));
  return item;
}
function browserStorage() { try { return globalThis.localStorage; } catch { return null; } }
function skeleton() { const node = document.createElement('div'); node.className = 'accommodation-card skeleton'; node.setAttribute('aria-hidden', 'true'); return node; }
function warningNode(message) { const node = element('p', message); node.className = 'warning'; return node; }
function addDefinition(list, term, value) { list.append(element('dt', term), element('dd', value)); }
function element(tag, text) { const node = document.createElement(tag); node.textContent = text; return node; }
