'use strict';
(() => {
  const { el, get, post, busy, debounce, skeletonRows, emptyRow, toast } = App;
  const $ = (id) => document.getElementById(id);
  let page = 1, controller;

  async function load() {
    controller?.abort();
    controller = new AbortController();
    const { signal } = controller;
    skeletonRows($('lignes'), 8, 6);
    $('precedent').disabled = $('suivant').disabled = true;
    try {
      const r = await get('/api/communes', { q: $('recherche').value.trim(), page }, { signal });
      page = r.page;
      $('lignes').replaceChildren(...(r.communes.length ? r.communes.map((c) => el('tr', {},
        ['code', 'nom', 'district', 'region'].map((k) => el('td', {}, c[k] || '—')),
        ['latitude', 'longitude'].map((k) => el('td', { class: 'num' }, c[k] ?? '—'))))
        : [emptyRow(6, 'Aucune commune trouvée.')]));
      $('totalCommunes').textContent = `${r.total.toLocaleString('fr-FR')} commune(s)`;
      $('position').textContent = `Page ${r.page} sur ${r.pages}`;
      $('precedent').disabled = r.page <= 1;
      $('suivant').disabled = r.page >= r.pages;
    } catch (error) {
      if (!signal.aborted) $('lignes').replaceChildren(emptyRow(6, error.message));
    }
  }

  $('recherche').addEventListener('input', debounce(() => { page = 1; load(); }));
  $('precedent').addEventListener('click', () => { page--; load(); });
  $('suivant').addEventListener('click', () => { page++; load(); });
  $('nouvelle').addEventListener('click', () => $('dialogue').showModal());
  $('annuler').addEventListener('click', () => $('dialogue').close());
  $('formCommune').addEventListener('submit', (event) => {
    event.preventDefault();
    const form = event.target;
    busy(event.submitter, async () => {
      await post('/api/communes', Object.fromEntries(new FormData(form)));
      form.reset(); $('dialogue').close(); toast('Commune ajoutée.'); load();
    });
  });
  load();
})();
