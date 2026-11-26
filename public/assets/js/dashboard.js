'use strict';
(() => {
  const { el, get, cached, debounce, toast } = App;
  const $ = (id) => document.getElementById(id);
  const charts = {};
  let diseases = [];
  let controller;
  let lastSeries;

  const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  const number = (v, digits = 1) => v == null ? '—' : v.toLocaleString('fr-FR', { maximumFractionDigits: digits });
  const month = (value) => new Date(`${value}-01T00:00:00`).toLocaleDateString('fr-FR', { month: 'short', year: 'numeric' });
  const day = (value) => new Date(`${value}T00:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: '2-digit' });

  function tile(name, value, sub) {
    const node = document.querySelector(`[data-tile="${name}"]`);
    node.querySelector('.value').textContent = value;
    node.querySelector('.value').classList.remove('skeleton');
    node.querySelector('.sub').textContent = sub;
  }

  function renderSummary({ climat, sante }) {
    const period = climat.jusqu_au ? `30 jours jusqu’au ${day(climat.jusqu_au)}` : 'Aucune donnée climatique';
    tile('temperature', climat.temperature == null ? '—' : `${number(climat.temperature)} °C`, period);
    tile('precipitation', climat.precipitation == null ? '—' : `${number(climat.precipitation)} mm`, climat.jusqu_au ? `Moyenne par jour · ${period}` : period);
    tile('humidite', climat.humidite == null ? '—' : `${number(climat.humidite, 0)} %`, period);
    let sub = sante.mois ? month(sante.mois) : 'Aucune donnée sanitaire';
    if (sante.cas_precedent != null) {
      const delta = sante.cas - sante.cas_precedent;
      sub += ` · ${delta > 0 ? '▲ +' : delta < 0 ? '▼ −' : '= '}${number(Math.abs(delta), 0)} vs mois précédent`;
    }
    tile('cas', number(sante.cas, 0), sub);
  }

  function panel(canvasId, hasData, config) {
    const canvas = $(canvasId);
    const box = canvas.parentElement;
    box.classList.remove('skeleton');
    box.querySelector('.empty')?.remove();
    charts[canvasId]?.destroy();
    if (!hasData) { box.append(el('div', { class: 'empty' }, 'Aucune donnée sur cette période.')); return; }
    charts[canvasId] = new Chart(canvas, config);
  }

  function options({ stacked = false, legend = false } = {}) {
    const grid = { color: css('--line') };
    const ticks = { color: css('--muted'), maxRotation: 0, autoSkip: true, autoSkipPadding: 16 };
    return {
      responsive: true, maintainAspectRatio: false, animation: { duration: 250 },
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { display: legend, position: 'bottom', labels: { color: css('--text'), usePointStyle: true, boxWidth: 8 } } },
      scales: { x: { stacked, grid: { display: false }, ticks }, y: { stacked, grid, ticks, border: { display: false } } },
    };
  }

  function renderSeries(data) {
    lastSeries = data;
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    const labels = data.climat.map((d) => day(d.date));
    panel('graphTemperature', data.climat.length, { type: 'line', options: options(), data: { labels, datasets: [{
      label: 'Température (°C)', data: data.climat.map((d) => d.temperature), borderColor: css('--series-2'), backgroundColor: css('--series-2'), borderWidth: 2, pointRadius: 0, tension: .25 }] } });
    panel('graphPluie', data.climat.length, { type: 'bar', options: options(), data: { labels, datasets: [{
      label: 'Précipitations (mm)', data: data.climat.map((d) => d.precipitation), backgroundColor: css('--series-1'), borderRadius: 3 }] } });

    const months = [...new Set(data.maladies.map((d) => d.mois))].sort();
    const names = [...new Set(data.maladies.map((d) => d.maladie))];
    // La couleur suit la maladie (ordre fixe de tout le catalogue), pas son rang dans le filtre.
    const order = [...new Set([...diseases, ...names])];
    const shown = order.filter((n) => names.includes(n));
    const main = shown.filter((n) => order.indexOf(n) < 7);
    const others = shown.filter((n) => order.indexOf(n) >= 7);
    const value = (m, n) => data.maladies.find((d) => d.mois === m && d.maladie === n)?.total ?? 0;
    const border = { borderColor: css('--surface'), borderWidth: { top: 2 }, borderRadius: 3 };
    const datasets = main.map((n) => ({ label: n, data: months.map((m) => value(m, n)), backgroundColor: css(`--series-${order.indexOf(n) + 1}`), ...border }));
    if (others.length) datasets.push({ label: 'Autres', data: months.map((m) => others.reduce((s, n) => s + value(m, n), 0)), backgroundColor: css('--risk-na'), ...border });
    panel('graphMaladies', months.length, { type: 'bar', options: options({ stacked: true, legend: true }), data: { labels: months.map(month), datasets } });

    $('tableMaladies').replaceChildren(el('table', {},
      el('thead', {}, el('tr', {}, el('th', {}, 'Mois'), shown.map((n) => el('th', { class: 'num' }, n)))),
      el('tbody', {}, months.map((m) => el('tr', {}, el('td', {}, month(m)), shown.map((n) => el('td', { class: 'num' }, number(value(m, n), 0))))))));
  }

  function fail(error) {
    toast(error.message, 'error');
    document.querySelectorAll('.value.skeleton').forEach((n) => n.classList.remove('skeleton'));
    document.querySelectorAll('.chart.skeleton').forEach((n) => { n.classList.remove('skeleton'); n.append(el('div', { class: 'empty' }, error.message)); });
  }

  async function load(fresh = false) {
    controller?.abort(); // une nouvelle sélection annule la précédente
    controller = new AbortController();
    const { signal } = controller;
    const commune = $('commune').value.trim();
    $('contexte').textContent = commune || 'Toutes les communes';
    const zones = document.querySelectorAll('.tiles, .grid');
    zones.forEach((z) => z.classList.add('refreshing'));
    const ignoreAbort = (error) => { if (!signal.aborted) fail(error); };
    // Requêtes indépendantes : chaque bloc s'affiche dès que ses données arrivent.
    await Promise.all([
      cached('/api/dashboard/summary', { commune }, renderSummary, { signal, fresh }).catch(ignoreAbort),
      cached('/api/dashboard/series', { commune, mois: $('mois').value }, renderSeries, { signal, fresh }).catch(ignoreAbort),
      correlation(signal),
    ]);
    if (!signal.aborted) zones.forEach((z) => z.classList.remove('refreshing'));
  }

  async function correlation(signal = controller?.signal) {
    const maladie = $('corrMaladie').value;
    const box = $('corrResultat');
    if (!maladie) return;
    box.replaceChildren(el('p', { class: 'muted' }, 'Calcul en cours…'));
    try {
      const r = await get('/api/correlation', { climat: $('corrClimat').value, maladie, commune: $('commune').value.trim() }, { signal });
      box.replaceChildren(
        el('p', { class: 'corr-value' }, r.coefficient == null ? '—' : number(r.coefficient, 2)),
        el('p', {}, r.interpretation),
        el('p', { class: 'muted small' }, `${r.nb_observations} observation(s) commune × mois`));
    } catch (error) {
      if (!signal?.aborted) box.replaceChildren(el('p', { class: 'muted' }, error.message));
    }
  }

  const reload = debounce(() => load(), 250);
  $('commune').addEventListener('change', reload);
  $('commune').addEventListener('search', reload);
  $('mois').addEventListener('change', reload);
  $('actualiser').addEventListener('click', () => load(true));
  $('corrClimat').addEventListener('change', () => correlation());
  $('corrMaladie').addEventListener('change', () => correlation());
  matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => lastSeries && renderSeries(lastSeries));

  cached('/api/dashboard/filters', {}, ({ communes, maladies }) => {
    diseases = maladies;
    $('listeCommunes').replaceChildren(...communes.map((c) => el('option', { value: c })));
    const select = $('corrMaladie');
    const current = select.value;
    select.replaceChildren(...(maladies.length ? maladies.map((m) => el('option', { value: m }, m)) : [el('option', { value: '' }, 'Aucune donnée')]));
    if (maladies.includes(current)) select.value = current;
    if (lastSeries) renderSeries(lastSeries);
    if (!current) correlation();
  }).catch(() => {});

  load();
})();
