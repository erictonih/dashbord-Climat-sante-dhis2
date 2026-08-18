// Tableau de bord : récupération des données et affichage Chart.js

const API_BASE = window.location.pathname.includes('/pages/')
  ? window.location.pathname.split('/pages/')[0] + '/api'
  : '/api';

let charts = {};

// Palette de couleurs cyclique pour un nombre variable de maladies
const PALETTE = ['#e63946', '#f4a261', '#2a9d8f', '#457b9d', '#8e44ad', '#e76f51', '#2ecc71'];

async function chargerDonnees(commune = '') {
  const params = new URLSearchParams();
  if (commune) params.set('commune', commune);

  const res = await fetch(`${API_BASE}/get_climat_data.php?${params.toString()}`);
  const data = await res.json();

  if (!data.success) return;

  const labels = data.climat.map(d => d.date);
  const temperatures = data.climat.map(d => d.temperature);
  const precipitations = data.climat.map(d => d.precipitation);

  const moisLabels = Object.keys(data.maladies);

  // Liste dynamique de toutes les maladies présentes dans les données
  const maladiesUniques = [...new Set(
    moisLabels.flatMap(m => Object.keys(data.maladies[m]))
  )];

  const datasetsMaladies = maladiesUniques.map((maladie, i) => ({
    label: maladie,
    data: moisLabels.map(m => data.maladies[m][maladie] || 0),
    backgroundColor: PALETTE[i % PALETTE.length],
  }));

  // --- Graphique température ---
  renderChart('chartTemperature', 'line', {
    labels,
    datasets: [{ label: 'Température (°C)', data: temperatures, borderColor: '#ff6b6b', tension: 0.3 }]
  });

  // --- Graphique maladies ---
  renderChart('chartMaladies', 'bar', {
    labels: moisLabels,
    datasets: datasetsMaladies
  });

  // --- Graphique comparaison climat / santé (double axe) ---
  renderChart('chartComparaison', 'line', {
    labels,
    datasets: [
      { label: 'Température (°C)', data: temperatures, borderColor: '#ff6b6b', yAxisID: 'y' },
      { label: 'Précipitations (mm)', data: precipitations, borderColor: '#457b9d', yAxisID: 'y1' },
    ]
  }, {
    scales: {
      y: { type: 'linear', position: 'left' },
      y1: { type: 'linear', position: 'right', grid: { drawOnChartArea: false } },
    }
  });

  // --- Tendances mensuelles (total cas toutes maladies) ---
  const totalCasParMois = moisLabels.map(m =>
    Object.values(data.maladies[m]).reduce((a, b) => a + b, 0)
  );
  renderChart('chartTendances', 'line', {
    labels: moisLabels,
    datasets: [{ label: 'Total cas / mois', data: totalCasParMois, borderColor: '#8e44ad', fill: true, backgroundColor: 'rgba(142,68,173,0.15)' }]
  });
}

function renderChart(canvasId, type, data, extraOptions = {}) {
  const ctx = document.getElementById(canvasId);
  if (!ctx) return;

  if (charts[canvasId]) {
    charts[canvasId].destroy();
  }

  charts[canvasId] = new Chart(ctx, {
    type,
    data,
    options: {
      responsive: true,
      plugins: { legend: { position: 'bottom' } },
      ...extraOptions,
    }
  });
}

document.addEventListener('DOMContentLoaded', () => {
  chargerDonnees();

  const select = document.getElementById('selectCommune');
  if (select) {
    select.addEventListener('change', (e) => chargerDonnees(e.target.value));
  }
});
