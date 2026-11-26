'use strict';
(() => {
  const { el, get, post, busy, job, skeletonRows, emptyRow, toast } = App;
  const $ = (id) => document.getElementById(id);

  // --- Diagnostic ---
  $('diagnostic').addEventListener('click', (event) => busy(event.currentTarget, async () => {
    const panel = $('panneauDiagnostic');
    panel.hidden = false;
    panel.replaceChildren(el('p', { class: 'muted' }, 'Connexion à DHIS2…'));
    try {
      const r = await get('/api/dhis2/overview');
      const detail = el('div', { 'aria-live': 'polite' });
      panel.replaceChildren(
        el('h2', {}, `Connexion réussie · DHIS2 ${r.version || ''}`),
        el('div', { class: 'table-wrap' }, el('table', {},
          el('thead', {}, el('tr', {}, ['Niveau', 'Nom', 'Total', 'Exemples'].map((h) => el('th', {}, h)))),
          el('tbody', {}, r.niveaux.map((n) => el('tr', {}, [n.level, n.name, n.total ?? '—', n.exemples.join(', ')].map((v) => el('td', {}, String(v)))))))),
        el('h3', {}, 'Jeux de données'),
        el('div', { class: 'results' }, r.jeux_de_donnees.map((ds) => el('div', {}, el('span', {}, `${ds.name} · ${ds.periodType}`),
          el('button', { type: 'button', class: 'btn btn-small', onclick: (e) => busy(e.currentTarget, async () => {
            const { dataset } = await get('/api/dhis2/dataset', { id: ds.id });
            const names = (dataset.dataSetElements || []).map((x) => x.dataElement.name);
            detail.replaceChildren(el('div', { class: 'banner' }, el('strong', {}, dataset.name),
              el('p', {}, `Variable à définir : DHIS2_DATASET_UID = ${dataset.id}`),
              el('p', { class: 'small' }, `${names.length} élément(s) : ${names.join(', ')}`)));
          }) }, 'Détails')))),
        detail);
    } catch (error) {
      panel.replaceChildren(el('div', { class: 'banner banner-error' }, error.message));
    }
  }));

  // --- Mappings ---
  async function loadMappings() {
    skeletonRows($('tableOU'), 3, 3); skeletonRows($('tableDE'), 3, 3);
    try {
      const r = await get('/api/dhis2/mappings');
      const remove = (type, cle, label) => el('button', { type: 'button', class: 'btn btn-small btn-danger', 'aria-label': `Retirer ${label}`,
        onclick: (e) => {
          if (!confirm(`Retirer l’association « ${label} » ?`)) return;
          busy(e.currentTarget, async () => { await post('/api/dhis2/mappings/delete', { type, cle }); await loadMappings(); });
        } }, 'Retirer');
      $('tableOU').replaceChildren(...(r.communes.length ? r.communes.map((m) => el('tr', {}, el('td', {}, m.commune), el('td', {}, m.ou_name || m.ou_id), el('td', {}, remove('commune', m.commune, m.commune)))) : [emptyRow(3, 'Aucune commune associée.')]));
      $('tableDE').replaceChildren(...(r.maladies.length ? r.maladies.map((m) => el('tr', {}, el('td', {}, m.maladie), el('td', {}, m.de_name || m.de_id), el('td', {}, remove('maladie', m.de_id, m.maladie)))) : [emptyRow(3, 'Aucune maladie associée.')]));
      $('nbOU').textContent = r.communes.length; $('nbDE').textContent = r.maladies.length;
    } catch (error) {
      $('tableOU').replaceChildren(emptyRow(3, error.message)); $('tableDE').replaceChildren(emptyRow(3, error.message));
    }
  }

  // --- Recherche puis association ---
  let pending;
  function openDialog(type, item) {
    pending = { type, id: item.id, nom_dhis2: item.name };
    const form = $('formMap');
    form.reset();
    $('titreMap').textContent = type === 'commune' ? 'Associer à une commune' : 'Associer à une maladie';
    $('nomMap').textContent = item.name;
    $('libelleMap').firstElementChild.textContent = type === 'commune' ? 'Nom exact de la commune' : 'Nom de la maladie (ex. : paludisme)';
    $('suggestions').replaceChildren();
    if (type === 'commune') {
      form.valeur.value = item.name;
      get('/api/communes', { q: item.name.slice(0, 80) }).then((r) => $('suggestions').replaceChildren(...r.communes.map((c) => el('option', { value: c.nom })))).catch(() => {});
    }
    $('dialogueMap').showModal();
  }
  $('annulerMap').addEventListener('click', () => $('dialogueMap').close());
  $('formMap').addEventListener('submit', (event) => {
    event.preventDefault();
    const value = event.target.valeur.value.trim();
    busy(event.submitter, async () => {
      await post('/api/dhis2/mappings', { ...pending, [pending.type]: value });
      $('dialogueMap').close(); toast('Association enregistrée.'); await loadMappings();
    });
  });

  function search(formId, resultsId, url, type) {
    $(formId).addEventListener('submit', (event) => {
      event.preventDefault();
      const box = $(resultsId);
      busy(event.submitter, async () => {
        box.replaceChildren(el('p', { class: 'muted' }, 'Recherche…'));
        try {
          const { resultats } = await get(url, { q: event.target.q.value.trim() });
          box.replaceChildren(...(resultats.length ? resultats.map((item) => el('div', {},
            el('span', {}, item.name, item.level ? el('span', { class: 'muted' }, ` · niveau ${item.level}${item.parent ? `, ${item.parent.name}` : ''}`) : null),
            el('button', { type: 'button', class: 'btn btn-small', onclick: () => openDialog(type, item) }, 'Associer'))) : [el('p', { class: 'muted' }, 'Aucun résultat.')]));
        } catch (error) { box.replaceChildren(el('p', { class: 'muted' }, error.message)); }
      });
    });
  }
  search('rechercheOU', 'resultatsOU', '/api/dhis2/orgunits', 'commune');
  search('rechercheDE', 'resultatsDE', '/api/dhis2/dataelements', 'maladie');

  // --- Association automatique ---
  let examined = 0;
  const auto = job($('auto'), async (offset) => {
    if (offset === 0) examined = 0;
    const r = await post('/api/dhis2/mappings/auto', { offset, limit: 20 });
    const lines = [
      ...r.maladies.mappees.map((m) => ({ text: `Maladie ${m.maladie} → ${m.de_name}${m.ambigu ? ' (plusieurs résultats : à vérifier)' : ''}` })),
      ...r.communes.mappees.map((c) => ({ text: `${c.commune} → ${c.ou_name}` })),
      ...r.communes.ignorees.map((c) => ({ text: `${c.commune} : ${c.raison}`, error: true })),
    ];
    if (r.lot.termine) loadMappings();
    examined += r.lot.traitees;
    return { next: r.lot.suivant, done: r.lot.termine, status: `${examined} commune(s) examinée(s)`, lines };
  });
  $('auto').querySelector('[data-start]').addEventListener('click', async () => { await auto(); loadMappings(); });

  // --- Import par lots : tranches de 12 mois, puis communes ---
  const form = $('formImport');
  const now = new Date();
  const ym = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
  form.fin.value = ym(now);
  form.debut.value = ym(new Date(now.getFullYear(), now.getMonth() - 5, 1));

  let chunks, limit, index, local;
  const importer = job(form, async () => {
    const [debut, fin] = chunks[index];
    const r = await post('/api/import/sante', { debut, fin, limit, offset: local });
    const lines = r.resultats.map((x) => ({ text: `${debut}–${fin} · ${x.commune} : ${x.erreur || `${x.lignes} valeur(s)`}`, error: Boolean(x.erreur) }));
    // Progression globale : communes × tranches de 12 mois.
    const total = r.lot.total * chunks.length;
    const next = r.lot.total * index + r.lot.suivant;
    const done = r.lot.termine && index === chunks.length - 1;
    const status = `Tranche ${index + 1}/${chunks.length} · ${Math.min(r.lot.suivant, r.lot.total)} / ${r.lot.total} communes`;
    local = r.lot.suivant;
    if (r.lot.termine && !done) { index++; local = 0; }
    return { next, total, done, status, lines };
  });

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const months = [];
    const [y1, m1] = form.debut.value.split('-').map(Number);
    const [y2, m2] = form.fin.value.split('-').map(Number);
    for (let d = new Date(y1, m1 - 1, 1); d <= new Date(y2, m2 - 1, 1); d.setMonth(d.getMonth() + 1)) months.push(ym(d).replace('-', ''));
    if (!months.length || months.length > 60) { toast('Choisissez une période de 1 à 60 mois.', 'error'); return; }
    chunks = [];
    for (let i = 0; i < months.length; i += 12) { const part = months.slice(i, i + 12); chunks.push([part[0], part.at(-1)]); }
    limit = Number(form.limit.value) || 20;
    index = 0;
    local = 0;
    importer();
  });

  loadMappings();
})();
