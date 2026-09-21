import { DemoJourneyAdapter, SearchFlow, validateSearch } from 'ecotrip/search-core';

const form = document.querySelector('[data-search-form]');
if (form) {
  const status = document.querySelector('[data-status]');
  const errorState = document.querySelector('[data-error-state]');
  const results = document.querySelector('[data-results]');
  const button = form.querySelector('button[type="submit"]');
  const fixture = JSON.parse(document.querySelector('#journeys-fixture').textContent);
  const localToday = () => {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
  };
  form.elements.departureDate.min = localToday();

  const clearFeedback = () => {
    form.querySelectorAll('[aria-invalid="true"]').forEach(field => field.removeAttribute('aria-invalid'));
    form.querySelectorAll('[data-error-for]').forEach(node => { node.textContent = ''; });
    errorState.hidden = true;
    errorState.textContent = '';
  };

  const render = state => {
    button.disabled = state.kind === 'loading';
    form.setAttribute('aria-busy', state.kind === 'loading' ? 'true' : 'false');
    results.replaceChildren();
    errorState.hidden = true;

    if (state.kind === 'loading') {
      status.textContent = 'Recherche dans la fixture de démonstration…';
      results.append(createSkeleton(), createSkeleton());
      return;
    }
    if (state.kind === 'error') {
      status.textContent = '';
      errorState.hidden = false;
      errorState.textContent = state.message;
      return;
    }
    if (state.kind === 'empty') {
      status.textContent = 'Aucun scénario de démonstration ne correspond à ce trajet. Essayez Paris vers Lyon.';
      return;
    }

    status.textContent = `${state.itineraries.length} résultat${state.itineraries.length > 1 ? 's' : ''} synthétique${state.itineraries.length > 1 ? 's' : ''}.`;
    state.itineraries.forEach(itinerary => results.append(createResultCard(itinerary)));
  };

  const flow = new SearchFlow(new DemoJourneyAdapter(fixture), render);

  form.addEventListener('submit', event => {
    event.preventDefault();
    clearFeedback();
    const data = new FormData(form);
    const request = {
      originId: data.get('originId'),
      destinationId: data.get('destinationId'),
      departureDate: data.get('departureDate'),
      returnDate: data.get('returnDate'),
      travelers: Number(data.get('travelers')),
      modes: data.getAll('modes[]'),
    };
    const errors = validateSearch(request, localToday());
    if (Object.keys(errors).length) {
      Object.entries(errors).forEach(([name, message]) => {
        const target = form.querySelector(`[data-error-for="${name}"]`);
        if (target) target.textContent = message;
        const field = name === 'modes' ? form.querySelector('input[name="modes[]"]') : form.elements[name];
        field?.setAttribute('aria-invalid', 'true');
      });
      status.textContent = 'Le formulaire contient des erreurs. Vérifiez les champs signalés.';
      const firstInvalid = form.querySelector('[aria-invalid="true"]');
      firstInvalid?.focus();
      return;
    }
    flow.search(request);
  });
}

function createSkeleton() {
  const node = document.createElement('div');
  node.className = 'result-card skeleton';
  node.setAttribute('aria-hidden', 'true');
  return node;
}

function createResultCard(itinerary) {
  const card = document.createElement('article');
  card.className = 'result-card';
  const mode = itinerary.legs[0]?.mode ?? 'mode inconnu';
  const names = { train: 'Train', coach: 'Car', walk: 'Marche', public_transport: 'Transports publics', bicycle: 'Vélo', carpool: 'Covoiturage', flight: 'Avion' };
  const heading = document.createElement('h3');
  heading.textContent = names[mode] ?? mode;
  const duration = document.createElement('p');
  duration.className = 'result-duration';
  duration.textContent = `${Math.floor(itinerary.durationMinutes / 60)} h ${String(itinerary.durationMinutes % 60).padStart(2, '0')} · durée de scénario`;
  const details = document.createElement('p');
  details.textContent = `${itinerary.transfers} correspondance${itinerary.transfers === 1 ? '' : 's'} · Impact carbone indisponible`;
  const badge = document.createElement('span');
  badge.className = 'result-badge';
  badge.textContent = 'Donnée synthétique — non réservable';
  card.append(heading, duration, details, badge);
  return card;
}
