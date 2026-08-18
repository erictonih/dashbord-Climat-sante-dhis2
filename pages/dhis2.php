<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="h3 mb-4">Import des données de santé (DHIS2)</h1>

<div class="alert alert-info">
  Ce module se connecte à ton instance DHIS2 (configurée dans <code>config/config.php</code>)
  pour récupérer des données de santé agrégées. Trois étapes : <strong>1)</strong> mapper des communes
  à leurs unités d'organisation DHIS2, <strong>2)</strong> mapper des éléments de données DHIS2 à des
  noms de maladie, <strong>3)</strong> lancer l'import.
</div>

<div class="card mb-4">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center">
      <h2 class="h6 mb-0">0. Diagnostic de connexion</h2>
      <button id="btnDiagnostic" class="btn btn-sm btn-outline-secondary">Tester la connexion DHIS2</button>
    </div>
    <div id="resultatDiagnostic" class="mt-3"></div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
      <h2 class="h6 mb-0">Mapping automatique (communes + maladies)</h2>
      <div class="d-flex gap-2 align-items-center">
        <label class="form-label mb-0 small">Lot :</label>
        <input type="number" id="autoMapLimit" class="form-control form-control-sm" value="100" style="width:80px">
        <label class="form-label mb-0 small">Offset :</label>
        <input type="number" id="autoMapOffset" class="form-control form-control-sm" value="0" style="width:80px">
        <button id="btnAutoMapping" class="btn btn-sm btn-primary">Lancer le mapping auto</button>
      </div>
    </div>
    <p class="text-muted small mt-2 mb-0">
      Ne mappe que les correspondances exactes et non ambiguës. Les cas incertains sont listés
      ci-dessous pour un mapping manuel via les sections 1 et 2. C'est ce même mécanisme qui
      tourne automatiquement chaque jour via <code>cron/cron_mapping.php</code>.
    </p>
    <div id="resultatAutoMapping" class="mt-3"></div>
  </div>
</div>

<div class="row g-4">
  <!-- Étape 1 : mapping communes -->
  <div class="col-lg-6">
    <div class="card">
      <div class="card-body">
        <h2 class="h6 mb-3">1. Mapper une commune → unité d'organisation DHIS2</h2>
        <div class="input-group mb-2">
          <input type="text" id="rechercheOU" class="form-control" placeholder="Rechercher dans DHIS2 (ex: Ambositra)">
          <button id="btnRechercheOU" class="btn btn-outline-secondary">Rechercher</button>
        </div>
        <div id="resultatsOU" class="mb-3"></div>

        <h3 class="h6 mt-4">Communes déjà mappées</h3>
        <table class="table table-sm">
          <thead><tr><th>Commune</th><th>Unité DHIS2</th><th></th></tr></thead>
          <tbody id="tableauOU"></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Étape 2 : mapping maladies -->
  <div class="col-lg-6">
    <div class="card">
      <div class="card-body">
        <h2 class="h6 mb-3">2. Mapper un élément de donnée DHIS2 → maladie</h2>
        <div class="input-group mb-2">
          <input type="text" id="rechercheDE" class="form-control" placeholder="Rechercher dans DHIS2 (ex: paludisme)">
          <button id="btnRechercheDE" class="btn btn-outline-secondary">Rechercher</button>
        </div>
        <div id="resultatsDE" class="mb-3"></div>

        <h3 class="h6 mt-4">Maladies déjà mappées</h3>
        <table class="table table-sm">
          <thead><tr><th>Maladie</th><th>Élément DHIS2</th><th></th></tr></thead>
          <tbody id="tableauDE"></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Étape 3 : import -->
<div class="card mt-4">
  <div class="card-body">
    <h2 class="h6 mb-3">3. Lancer l'import</h2>
    <div class="alert alert-warning small">
      ⚠️ Sur InfinityFree, une seule requête PHP a un temps d'exécution limité. Importer beaucoup
      de communes × beaucoup de mois <strong>en un seul clic</strong> ("Importer ce lot") peut
      dépasser cette limite et provoquer une erreur "Unexpected token '&lt;'" (page d'erreur HTML
      au lieu du JSON attendu). Utilise plutôt <strong>"Importer automatiquement"</strong> ci-dessous :
      il enchaîne lui-même de petits lots (1 mois × quelques communes à la fois) depuis ton
      navigateur, sans jamais dépasser la limite serveur.
    </div>
    <div class="row g-2 align-items-end mb-3">
      <div class="col-auto">
        <label class="form-label">Période début (AAAAMM)</label>
        <input type="text" id="periodeDebut" class="form-control" placeholder="202501" value="202501" style="width:120px">
      </div>
      <div class="col-auto">
        <label class="form-label">Période fin (AAAAMM)</label>
        <input type="text" id="periodeFin" class="form-control" placeholder="202506" value="202506" style="width:120px">
      </div>
      <div class="col-auto">
        <label class="form-label">Lot (nb communes)</label>
        <input type="number" id="lotTaille" class="form-control" value="5" style="width:100px">
      </div>
      <div class="col-auto">
        <label class="form-label">Offset</label>
        <input type="number" id="lotOffset" class="form-control" value="0" style="width:100px">
      </div>
      <div class="col-auto">
        <button id="btnImporterSante" class="btn btn-outline-success">Importer ce lot (manuel, 1 requête)</button>
      </div>
      <div class="col-auto">
        <button id="btnImportAuto" class="btn btn-success">Importer automatiquement (recommandé)</button>
      </div>
      <div class="col-auto">
        <button id="btnStopAuto" class="btn btn-outline-danger" style="display:none;">Arrêter</button>
      </div>
    </div>
    <div id="resultatImportSante"></div>
  </div>
</div>

<script>
const API = '<?= BASE_URL ?>/api';

// --- Diagnostic de connexion ---
document.getElementById('btnDiagnostic').addEventListener('click', async () => {
  const div = document.getElementById('resultatDiagnostic');
  div.innerHTML = '<div class="spinner-border spinner-border-sm"></div> Test en cours...';

  try {
    const res = await fetch(`${API}/dhis2_test_connexion.php`);
    const data = await res.json();

    if (!data.success) {
      div.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
      return;
    }

    let html = `<div class="alert alert-success">Connexion OK — DHIS2 version ${data.dhis2_version}</div>`;

    html += '<h4 class="h6">Niveaux hiérarchiques (repère celui qui correspond à tes communes) :</h4>';
    html += '<table class="table table-sm"><thead><tr><th>Niveau</th><th>Nom</th><th>Total</th><th>Exemples</th></tr></thead><tbody>';
    data.niveaux_organisation.forEach(n => {
      html += `<tr><td>${n.level}</td><td>${n.name}</td><td>${n.total ?? '—'}</td><td>${n.exemples.join(', ')}</td></tr>`;
    });
    html += '</tbody></table>';

    html += '<h4 class="h6 mt-3">Jeux de données disponibles (clique pour voir son contenu) :</h4>';
    html += '<ul class="small" id="listeDatasets">';
    data.jeux_de_donnees.forEach(ds => {
      html += `<li><a href="#" class="lien-dataset" data-id="${ds.id}">${ds.name}</a> <span class="text-muted">(${ds.periodType}, id: <code>${ds.id}</code>)</span></li>`;
    });
    html += '</ul>';
    html += '<div id="detailDataset"></div>';

    div.innerHTML = html;

    div.querySelectorAll('.lien-dataset').forEach(lien => {
      lien.addEventListener('click', async (e) => {
        e.preventDefault();
        const detailDiv = document.getElementById('detailDataset');
        detailDiv.innerHTML = '<div class="spinner-border spinner-border-sm"></div>';

        const res2 = await fetch(`${API}/dhis2_test_connexion.php?dataset=${lien.dataset.id}`);
        const data2 = await res2.json();
        const ds = data2.detail_dataset;

        if (!ds) {
          detailDiv.innerHTML = '<div class="text-muted small">Impossible de charger ce dataSet.</div>';
          return;
        }

        let elements = (ds.dataSetElements || []).map(dse => dse.dataElement.name);
        detailDiv.innerHTML = `
          <div class="alert alert-secondary">
            <strong>${ds.name}</strong> (id: <code>${ds.id}</code>, période: ${ds.periodType})<br>
            <span class="small">${elements.length} élément(s) : ${elements.join(', ')}</span><br>
            <button class="btn btn-sm btn-success mt-2 btn-choisir-dataset" data-id="${ds.id}">Utiliser ce dataSet (DHIS2_DATASET_UID)</button>
          </div>`;

        detailDiv.querySelector('.btn-choisir-dataset').addEventListener('click', () => {
          alert(`Copie cet id dans config/config.php :\n\ndefine('DHIS2_DATASET_UID', '${ds.id}');`);
        });
      });
    });
  } catch (e) {
    div.innerHTML = `<div class="alert alert-danger">Erreur réseau : ${e.message}</div>`;
  }
});

// --- Mapping automatique ---
document.getElementById('btnAutoMapping').addEventListener('click', async () => {
  const div = document.getElementById('resultatAutoMapping');
  div.innerHTML = '<div class="spinner-border spinner-border-sm"></div> Mapping automatique en cours...';

  const params = new URLSearchParams({
    limit: document.getElementById('autoMapLimit').value,
    offset: document.getElementById('autoMapOffset').value,
  });

  try {
    const res = await fetch(`${API}/dhis2_auto_mapping.php?${params.toString()}`);
    const data = await res.json();

    if (!data.success) {
      div.innerHTML = `<div class="alert alert-danger">Erreur lors du mapping.</div>`;
      return;
    }

    let html = '';

    html += `<h4 class="h6">Maladies : ${data.maladies.mappees.length} mappée(s), ${data.maladies.ignorees.length} ignorée(s)</h4>`;
    if (data.maladies.mappees.length) {
      html += '<ul class="small">' + data.maladies.mappees.map(m =>
        `<li>${m.maladie} → ${m.de_name}${m.ambigu ? ' <span class="text-warning">(plusieurs résultats, 1er choisi)</span>' : ''}</li>`
      ).join('') + '</ul>';
    }
    if (data.maladies.ignorees.length) {
      html += '<ul class="small text-muted">' + data.maladies.ignorees.map(m =>
        `<li>${m.maladie ?? ''} : ${m.raison}</li>`
      ).join('') + '</ul>';
    }

    html += `<h4 class="h6 mt-3">Communes : ${data.communes.mappees.length} mappée(s), ${data.communes.ignorees.length} ignorée(s) sur ce lot</h4>`;
    if (data.communes.mappees.length) {
      html += '<ul class="small">' + data.communes.mappees.map(c =>
        `<li>${c.commune} → ${c.ou_name}</li>`
      ).join('') + '</ul>';
    }
    if (data.communes.ignorees.length) {
      html += '<details class="small text-muted"><summary>Communes ignorées (mapping manuel requis)</summary><ul>' +
        data.communes.ignorees.map(c => `<li>${c.commune ?? ''} : ${c.raison}</li>`).join('') +
        '</ul></details>';
    }
    if (data.communes.lot) {
      html += `<p class="small text-muted mt-2">Prochain lot : offset=${data.communes.lot.suivant}</p>`;
      document.getElementById('autoMapOffset').value = data.communes.lot.suivant;
    }

    div.innerHTML = html;
    chargerMappings();
  } catch (e) {
    div.innerHTML = `<div class="alert alert-danger">Erreur réseau : ${e.message}</div>`;
  }
});

// --- Recherche & mapping unités d'organisation ---
document.getElementById('btnRechercheOU').addEventListener('click', async () => {
  const q = document.getElementById('rechercheOU').value.trim();
  if (!q) return;
  const res = await fetch(`${API}/dhis2_search_orgunits.php?q=${encodeURIComponent(q)}`);
  const data = await res.json();
  const div = document.getElementById('resultatsOU');

  if (!data.success || data.resultats.length === 0) {
    div.innerHTML = '<div class="text-muted small">Aucun résultat.</div>';
    return;
  }

  div.innerHTML = data.resultats.map(ou => `
    <div class="d-flex justify-content-between align-items-center border-bottom py-1">
      <span>${ou.name} <span class="text-muted small">(niveau ${ou.level})</span></span>
      <button class="btn btn-sm btn-outline-primary btn-map-ou" data-id="${ou.id}" data-name="${ou.name.replace(/"/g, '&quot;')}">Mapper</button>
    </div>
  `).join('');

  div.querySelectorAll('.btn-map-ou').forEach(btn => {
    btn.addEventListener('click', async () => {
      const commune = prompt(`Nom exact de la commune (table communes) à associer à "${btn.dataset.name}" :`);
      if (!commune) return;
      await fetch(`${API}/dhis2_mapping.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'map_ou', commune, ou_id: btn.dataset.id, ou_name: btn.dataset.name })
      });
      chargerMappings();
    });
  });
});

// --- Recherche & mapping éléments de données ---
document.getElementById('btnRechercheDE').addEventListener('click', async () => {
  const q = document.getElementById('rechercheDE').value.trim();
  if (!q) return;
  const res = await fetch(`${API}/dhis2_search_dataelements.php?q=${encodeURIComponent(q)}`);
  const data = await res.json();
  const div = document.getElementById('resultatsDE');

  if (!data.success || data.resultats.length === 0) {
    div.innerHTML = '<div class="text-muted small">Aucun résultat.</div>';
    return;
  }

  div.innerHTML = data.resultats.map(de => `
    <div class="d-flex justify-content-between align-items-center border-bottom py-1">
      <span>${de.name}</span>
      <button class="btn btn-sm btn-outline-primary btn-map-de" data-id="${de.id}" data-name="${de.name.replace(/"/g, '&quot;')}">Mapper</button>
    </div>
  `).join('');

  div.querySelectorAll('.btn-map-de').forEach(btn => {
    btn.addEventListener('click', async () => {
      const maladie = prompt(`Nom de maladie à utiliser (ex: paludisme, diarrhee) pour "${btn.dataset.name}" :`);
      if (!maladie) return;
      await fetch(`${API}/dhis2_mapping.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'map_de', de_id: btn.dataset.id, de_name: btn.dataset.name, maladie })
      });
      chargerMappings();
    });
  });
});

// --- Chargement des tableaux de mappings existants ---
async function chargerMappings() {
  const res = await fetch(`${API}/dhis2_mapping.php`);
  const data = await res.json();
  if (!data.success) return;

  document.getElementById('tableauOU').innerHTML = data.communes_mappees.map(m => `
    <tr>
      <td>${m.commune}</td>
      <td>${m.ou_name ?? m.ou_id}</td>
      <td><button class="btn btn-sm btn-outline-danger btn-del-ou" data-commune="${m.commune.replace(/"/g, '&quot;')}">✕</button></td>
    </tr>
  `).join('') || '<tr><td colspan="3" class="text-muted">Aucune commune mappée.</td></tr>';

  document.getElementById('tableauDE').innerHTML = data.maladies_mappees.map(m => `
    <tr>
      <td>${m.maladie}</td>
      <td>${m.de_name ?? m.de_id}</td>
      <td><button class="btn btn-sm btn-outline-danger btn-del-de" data-id="${m.de_id}">✕</button></td>
    </tr>
  `).join('') || '<tr><td colspan="3" class="text-muted">Aucune maladie mappée.</td></tr>';

  document.querySelectorAll('.btn-del-ou').forEach(btn => btn.addEventListener('click', async () => {
    await fetch(`${API}/dhis2_mapping.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ action: 'delete_ou', commune: btn.dataset.commune })
    });
    chargerMappings();
  }));

  document.querySelectorAll('.btn-del-de').forEach(btn => btn.addEventListener('click', async () => {
    await fetch(`${API}/dhis2_mapping.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ action: 'delete_de', de_id: btn.dataset.id })
    });
    chargerMappings();
  }));
}

// --- Lancement de l'import (manuel : 1 seule requête) ---
document.getElementById('btnImporterSante').addEventListener('click', async () => {
  const params = new URLSearchParams({
    period_start: document.getElementById('periodeDebut').value,
    period_end: document.getElementById('periodeFin').value,
    limit: document.getElementById('lotTaille').value,
    offset: document.getElementById('lotOffset').value,
  });

  const div = document.getElementById('resultatImportSante');
  div.innerHTML = '<div class="spinner-border spinner-border-sm"></div> Import en cours...';

  try {
    const res = await fetch(`${API}/import_sante_dhis2.php?${params.toString()}`);
    const data = await res.json();

    if (!data.success) {
      div.innerHTML = `<div class="alert alert-warning">${data.message}</div>`;
      return;
    }

    let html = `<p>Périodes : ${data.periodes.join(', ')}</p>`;
    html += '<table class="table table-sm"><thead><tr><th>Commune</th><th>Lignes importées</th></tr></thead><tbody>';
    data.resultats.forEach(r => html += `<tr><td>${r.commune}</td><td>${r.lignes_importees}</td></tr>`);
    html += '</tbody></table>';
    div.innerHTML = html;
  } catch (e) {
    div.innerHTML = `<div class="alert alert-danger">Erreur réseau : ${e.message}<br><small>Si l'erreur mentionne un token "&lt;" inattendu, c'est probablement un timeout serveur — utilise plutôt "Importer automatiquement" avec un lot plus petit.</small></div>`;
  }
});

// --- Import automatique : enchaîne 1 mois × un petit lot de communes à la fois ---
let importAutoEnCours = false;

document.getElementById('btnStopAuto').addEventListener('click', () => {
  importAutoEnCours = false;
});

document.getElementById('btnImportAuto').addEventListener('click', async () => {
  const debut = document.getElementById('periodeDebut').value.trim();
  const fin = document.getElementById('periodeFin').value.trim();
  const lot = Math.max(1, parseInt(document.getElementById('lotTaille').value) || 5);
  const div = document.getElementById('resultatImportSante');

  if (!/^\d{6}$/.test(debut) || !/^\d{6}$/.test(fin)) {
    div.innerHTML = '<div class="alert alert-danger">Format de période invalide (attendu AAAAMM, ex: 202501).</div>';
    return;
  }

  // Génère la liste des mois entre début et fin, côté navigateur
  const periodes = [];
  let y = parseInt(debut.slice(0, 4)), m = parseInt(debut.slice(4, 6));
  const yFin = parseInt(fin.slice(0, 4)), mFin = parseInt(fin.slice(4, 6));
  while (y < yFin || (y === yFin && m <= mFin)) {
    periodes.push(`${y}${String(m).padStart(2, '0')}`);
    m++;
    if (m > 12) { m = 1; y++; }
  }

  importAutoEnCours = true;
  document.getElementById('btnImportAuto').disabled = true;
  document.getElementById('btnImporterSante').disabled = true;
  document.getElementById('btnStopAuto').style.display = 'inline-block';

  div.innerHTML = '';
  const log = document.createElement('div');
  log.className = 'small';
  div.appendChild(log);

  let totalLignes = 0;

  for (const periode of periodes) {
    if (!importAutoEnCours) break;

    let offset = 0;
    let continuer = true;

    while (continuer && importAutoEnCours) {
      const ligne = document.createElement('p');
      ligne.className = 'mb-1 text-muted';
      ligne.textContent = `Période ${periode}, communes ${offset} à ${offset + lot - 1}...`;
      log.appendChild(ligne);
      log.scrollTop = log.scrollHeight;

      const params = new URLSearchParams({
        period_start: periode, period_end: periode, limit: lot, offset,
      });

      try {
        const res = await fetch(`${API}/import_sante_dhis2.php?${params.toString()}`);
        const data = await res.json();

        if (!data.success) {
          ligne.className = 'mb-1 text-danger';
          ligne.textContent += ` → ${data.message}`;
          continuer = false;
          break;
        }

        const lignesLot = data.resultats.reduce((s, r) => s + r.lignes_importees, 0);
        totalLignes += lignesLot;
        ligne.className = 'mb-1 text-success';
        ligne.textContent += ` → ${data.resultats.length} commune(s), ${lignesLot} ligne(s) importées`;

        if (data.resultats.length < lot) {
          continuer = false; // plus de communes à ce niveau d'offset pour cette période
        } else {
          offset += lot;
        }
      } catch (e) {
        ligne.className = 'mb-1 text-danger';
        ligne.textContent += ` → erreur réseau : ${e.message}`;
        continuer = false;
      }
    }
  }

  const fin2 = document.createElement('p');
  fin2.className = 'fw-bold mt-2';
  fin2.textContent = importAutoEnCours
    ? `Import automatique terminé. Total : ${totalLignes} ligne(s) importées.`
    : `Import arrêté par l'utilisateur. Total jusqu'ici : ${totalLignes} ligne(s).`;
  log.appendChild(fin2);

  importAutoEnCours = false;
  document.getElementById('btnImportAuto').disabled = false;
  document.getElementById('btnImporterSante').disabled = false;
  document.getElementById('btnStopAuto').style.display = 'none';
});

document.addEventListener('DOMContentLoaded', chargerMappings);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
