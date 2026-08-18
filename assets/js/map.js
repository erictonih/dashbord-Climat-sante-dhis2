// Carte Leaflet : affichage des communes colorées selon le niveau de risque

const API_BASE_MAP = window.location.pathname.includes('/pages/')
  ? window.location.pathname.split('/pages/')[0] + '/api'
  : '/api';

document.addEventListener('DOMContentLoaded', async () => {
  const map = L.map('carte').setView([-18.9, 47.5], 6); // centré sur Madagascar par défaut

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors',
    maxZoom: 18,
  }).addTo(map);

  try {
    const res = await fetch(`${API_BASE_MAP}/get_communes.php`);
    const data = await res.json();

    if (!data.success) return;

    const bounds = [];

    data.communes.forEach(c => {
      if (!c.latitude || !c.longitude) return;

      const marker = L.circleMarker([c.latitude, c.longitude], {
        radius: 12,
        fillColor: c.couleur || '#6c757d',
        color: '#333',
        weight: 1,
        fillOpacity: 0.85,
      }).addTo(map);

      marker.bindPopup(`
        <h3>${c.nom}</h3>
        <table>
          <tr><td>Région</td><td>${c.region ?? '—'}</td></tr>
          <tr><td>🌡️ Température</td><td>${c.temperature ?? '—'} °C</td></tr>
          <tr><td>🌧️ Pluie</td><td>${c.precipitation ?? '—'} mm</td></tr>
          <tr><td>💧 Humidité</td><td>${c.humidite ?? '—'} %</td></tr>
          <tr><td>🦟 Cas (30j)</td><td>${c.nb_cas_30j ?? 0}</td></tr>
          <tr><td>⚠️ Risque</td><td>${c.niveau ?? 'inconnu'} (${c.indice_risque ?? '—'}%)</td></tr>
        </table>
      `);

      bounds.push([c.latitude, c.longitude]);
    });

    if (bounds.length) {
      map.fitBounds(bounds, { padding: [40, 40] });
    }
  } catch (e) {
    console.error('Erreur de chargement des communes', e);
  }
});
