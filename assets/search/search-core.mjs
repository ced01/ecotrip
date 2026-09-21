export function validateSearch(request, today) {
  const errors = {};
  if (!request.originId) errors.originId = 'Choisissez un point de départ.';
  if (!request.destinationId) errors.destinationId = 'Choisissez une destination.';
  else if (request.destinationId === request.originId) errors.destinationId = 'La destination doit être différente du départ.';

  if (!request.departureDate) {
    errors.departureDate = 'Choisissez une date de départ.';
  } else if (!isIsoDate(request.departureDate)) {
    errors.departureDate = 'Choisissez une date de départ valide.';
  } else if (request.departureDate < today) {
    errors.departureDate = 'La date de départ ne peut pas être passée.';
  }
  if (request.returnDate && !isIsoDate(request.returnDate)) {
    errors.returnDate = 'Choisissez une date de retour valide.';
  } else if (request.returnDate && isIsoDate(request.departureDate) && request.returnDate < request.departureDate) {
    errors.returnDate = 'La date de retour doit suivre le départ.';
  }
  if (!Number.isInteger(request.travelers) || request.travelers < 1 || request.travelers > 9) {
    errors.travelers = 'Choisissez entre 1 et 9 voyageurs.';
  }
  if (!Array.isArray(request.modes) || request.modes.length === 0) {
    errors.modes = 'Choisissez au moins un mode de transport.';
  }
  return errors;
}

function isIsoDate(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value ?? '')) return false;
  const [year, month, day] = value.split('-').map(Number);
  const date = new Date(Date.UTC(year, month - 1, day));
  return date.getUTCFullYear() === year
    && date.getUTCMonth() === month - 1
    && date.getUTCDate() === day;
}

export class DemoJourneyAdapter {
  constructor(fixture, { delay = () => new Promise(resolve => setTimeout(resolve, 550)) } = {}) {
    this.fixture = fixture;
    this.delay = delay;
  }

  async search(request) {
    await this.delay();
    if (!this.fixture?.request || !this.fixture?.outbound?.itineraries) {
      throw new Error('Invalid contractual fixture');
    }

    const isCovered = request.originId === this.fixture.request.originId
      && request.destinationId === this.fixture.request.destinationId;
    if (!isCovered) return { kind: 'empty', itineraries: [], dataMode: 'demo' };

    const itineraries = this.fixture.outbound.itineraries.filter(itinerary =>
      itinerary.legs.some(leg => request.modes.includes(leg.mode)),
    );
    return {
      kind: itineraries.length ? 'success' : 'empty',
      itineraries,
      dataMode: this.fixture.dataMode,
    };
  }
}

export class SearchFlow {
  #sequence = 0;

  constructor(adapter, render) {
    this.adapter = adapter;
    this.render = render;
  }

  async search(request) {
    const sequence = ++this.#sequence;
    this.render({ kind: 'loading' });
    try {
      const result = await this.adapter.search(request);
      if (sequence === this.#sequence) this.render(result);
    } catch {
      if (sequence === this.#sequence) {
        this.render({ kind: 'error', message: 'Impossible de charger la démonstration. Réessayez.' });
      }
    }
  }
}
