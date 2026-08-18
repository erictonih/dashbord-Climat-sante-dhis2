<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="h3 mb-4">Import des données climatiques</h1>

<div class="alert alert-warning">
  <strong>Important :</strong> ta base contient environ 1742 communes. Importer les données
  climatiques de toutes les communes en une seule fois enverrait ~1742 requêtes à l'API NASA POWER,
  ce qui dépasserait largement les limites de temps d'exécution d'InfinityFree.
  Utilise donc l'import <strong>par lots</strong> ci-dessous (ex: 20 à 50 communes à la fois),
  en cliquant plusieurs fois de suite ou via une tâche planifiée répétée.
</div>

<div class="card">
  <div class="card-body">
    <div class="row g-2 align-items-end mb-3">
      <div class="col-auto">
        <label class="form-label">Date début</label>
        <input type="date" id="dateDebut" class="form-control">
      </div>
      <div class="col-auto">
        <label class="form-label">Date fin</label>
        <input type="date" id="dateFin" class="form-control">
      </div>
      <div class="col-auto">
        <label class="form-label">Taille du lot</label>
        <input type="number" id="tailleLot" class="form-control" value="20" min="1" max="100">
      </div>
      <div class="col-auto">
        <label class="form-label">Départ (offset)</label>
        <input type="number" id="offsetLot" class="form-control" value="0" min="0">
      </div>
      <div class="col-auto">
        <button id="btnImporter" class="btn btn-success">
          <i class="bi bi-cloud-download"></i> Importer ce lot
        </button>
      </div>
      <div class="col-auto">
        <button id="btnImporterSuite" class="btn btn-outline-success" style="display:none;">
          Importer le lot suivant →
        </button>
      </div>
    </div>

    <div class="mb-3">
      <label class="form-label">Ou une seule commune :</label>
      <input type="text" id="communeUnique" class="form-control d-inline-block w-auto" placeholder="ex: Ambositra I">
      <button id="btnImporterUnique" class="btn btn-outline-primary btn-sm">Importer cette commune</button>
    </div>

    <div id="resultatImport" class="mt-3"></div>
  </div>
</div>

<div class="alert alert-info mt-4">
  <strong>Astuce :</strong> pour automatiser cet import par lots, configurez une tâche planifiée
  (webcron, ex: cron-job.org) pointant vers :
  <code><?= BASE_URL ?>/api/import_climat.php?limit=50&offset=0</code>
  puis une seconde tâche avec <code>offset=50</code>, etc.
</div>

<script>
const API_URL = '<?= BASE_URL ?>/api/import_climat.php';
let prochainOffset = 0;

async function lancerImport(params) {
  const resDiv = document.getElementById('resultatImport');
  resDiv.innerHTML = '<div class="spinner-border spinner-border-sm"></div> Import en cours (cela peut prendre du temps)...';

  try {
    const res = await fetch(`${API_URL}?${params.toString()}`);
    const data = await res.json();

    if (data.success) {
      let html = `<p>Période : ${data.periode.start} → ${data.periode.end}</p>`;
      if (data.lot) {
        html += `<p>Lot : offset ${data.lot.limit !== undefined ? '' : ''}${data.lot.offset ?? ''} / taille ${data.lot.limit ?? ''}</p>`;
        prochainOffset = data.lot.suivant ?? 0;
        document.getElementById('btnImporterSuite').style.display = 'inline-block';
      }
      html += '<table class="table table-sm"><thead><tr><th>Commune</th><th>Lignes importées</th></tr></thead><tbody>';
      data.resultats.forEach(r => {
        html += `<tr><td>${r.commune}</td><td>${r.lignes_importees}</td></tr>`;
      });
      html += '</tbody></table>';
      resDiv.innerHTML = html;
    } else {
      resDiv.innerHTML = '<div class="alert alert-danger">Erreur lors de l\'import.</div>';
    }
  } catch (e) {
    resDiv.innerHTML = '<div class="alert alert-danger">Erreur réseau : ' + e.message + '</div>';
  }
}

function datesParams() {
  const start = document.getElementById('dateDebut').value.replace(/-/g, '');
  const end = document.getElementById('dateFin').value.replace(/-/g, '');
  const params = new URLSearchParams();
  if (start) params.set('start', start);
  if (end) params.set('end', end);
  return params;
}

document.getElementById('btnImporter').addEventListener('click', () => {
  const params = datesParams();
  params.set('limit', document.getElementById('tailleLot').value);
  params.set('offset', document.getElementById('offsetLot').value);
  lancerImport(params);
});

document.getElementById('btnImporterSuite').addEventListener('click', () => {
  const params = datesParams();
  params.set('limit', document.getElementById('tailleLot').value);
  params.set('offset', prochainOffset);
  document.getElementById('offsetLot').value = prochainOffset;
  lancerImport(params);
});

document.getElementById('btnImporterUnique').addEventListener('click', () => {
  const params = datesParams();
  const commune = document.getElementById('communeUnique').value.trim();
  if (!commune) return;
  params.set('commune', commune);
  lancerImport(params);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
