const presentText = value => typeof value === 'string' && value.trim() !== '' ? value : null;

export function safeHttpUrl(value) {
  if (typeof value !== 'string' || value.trim() === '') return null;
  try {
    const url = new URL(value);
    return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : null;
  } catch {
    return null;
  }
}

export function formatProvenance(provenance) {
  const status = presentText(provenance?.status) ?? 'Inconnu';
  const sourceIds = Array.isArray(provenance?.sourceIds)
    ? provenance.sourceIds.map(presentText).filter(Boolean)
    : [];
  const asOf = presentText(provenance?.asOf) ?? 'non renseigné';
  const fields = [`statut : ${status}`, `sources : ${sourceIds.length ? sourceIds.join(', ') : 'non renseignées'}`, `au : ${asOf}`];
  const note = presentText(provenance?.note);
  if (note) fields.push(`note : ${note}`);
  return fields.join(' · ');
}

export function formatCarbonFactor(factor = {}) {
  const id = presentText(factor?.id) ?? 'Facteur inconnu';
  const unit = presentText(factor?.unit);
  const value = Number.isFinite(factor?.value)
    ? `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 12 }).format(factor.value)}${unit ? ` ${unit}` : ' (unité non renseignée)'}`
    : 'valeur non renseignée';
  const mode = presentText(factor?.mode) ?? 'Inconnu';
  const sourceId = presentText(factor?.sourceId) ?? 'non renseignée';
  return `${id} — ${value} · mode : ${mode} · source : ${sourceId}`;
}
