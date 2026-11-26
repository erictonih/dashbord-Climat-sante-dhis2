'use strict';
(() => {
  const { el, get, toast } = App;
  const box = document.getElementById('carte');
  const map = L.map(box, { preferCanvas: true }).setView([-18.9, 47.5], 6);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; contributeurs OpenStreetMap', maxZoom: 18 }).addTo(map);
  const style = getComputedStyle(document.documentElement);
  const levels = { faible: ['--risk-low', 6, 'Faible'], moyen: ['--risk-mid', 8, 'Moyen'], eleve: ['--risk-high', 10, 'Élevé'], inconnu: ['--risk-na', 5, 'Inconnu'] };
  const layers = Object.fromEntries(Object.keys(levels).map((k) => [k, L.layerGroup().addTo(map)]));
  const value = (v, unit) => v == null ? '—' : `${v.toLocaleString('fr-FR')}${unit}`;

  const popup = (c) => el('div', {}, el('strong', {}, c.nom), el('table', {}, el('tbody', {},
    [['Région', c.region || '—'], ['District', c.district || '—'], ['Température', value(c.temperature, ' °C')],
      ['Pluie', value(c.precipitation, ' mm')], ['Humidité', value(c.humidite, ' %')], ['Cas', value(c.cas, '')],
      ['Risque', c.indice == null ? 'Inconnu' : `${levels[c.niveau][2]} (${c.indice} %)`]]
      .map(([k, v]) => el('tr', {}, el('td', { class: 'muted' }, k), el('td', {}, v))))));

  document.querySelectorAll('.legend input').forEach((input) => input.addEventListener('change', () => {
    input.checked ? layers[input.value].addTo(map) : layers[input.value].remove();
  }));

  get('/api/map').then(({ communes, mois }) => {
    const bounds = [];
    // Les risques élevés sont dessinés en dernier pour rester visibles.
    const rank = { inconnu: 0, faible: 1, moyen: 2, eleve: 3 };
    for (const c of [...communes].sort((a, b) => rank[a.niveau] - rank[b.niveau])) {
      const [color, radius] = levels[c.niveau];
      L.circleMarker([c.lat, c.lon], { radius, fillColor: style.getPropertyValue(color).trim(), color: '#fff', weight: 1, fillOpacity: .9 })
        .bindPopup(() => popup(c)).addTo(layers[c.niveau]);
      bounds.push([c.lat, c.lon]);
    }
    if (bounds.length) map.fitBounds(bounds, { padding: [30, 30] });
    document.getElementById('resumeCarte').textContent = communes.length
      ? `${communes.length} commune(s)${mois ? ` · cas du mois ${mois.split('-').reverse().join('/')}` : ' · aucune donnée sanitaire'}`
      : 'Aucune commune avec des données climatiques.';
  }).catch((error) => {
    document.getElementById('resumeCarte').textContent = error.message;
    toast(error.message, 'error');
  }).finally(() => box.classList.remove('skeleton'));
})();
