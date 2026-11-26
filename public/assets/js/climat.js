'use strict';
(() => {
  const { post, job } = App;
  const form = document.getElementById('formClimat');
  const iso = (d) => d.toLocaleDateString('fr-CA');
  form.fin.value = iso(new Date());
  form.debut.value = iso(new Date(Date.now() - 30 * 86400000));

  let params;
  const run = job(form, async (offset) => {
    const r = await post('/api/import/climat', { ...params, offset });
    return {
      next: r.lot.suivant, total: r.lot.total, done: r.lot.termine,
      lines: r.resultats.map((x) => ({ text: `${x.commune} : ${x.erreur || `${x.lignes} jour(s)`}`, error: Boolean(x.erreur) })),
    };
  });
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    params = Object.fromEntries(new FormData(form));
    run();
  });
})();
