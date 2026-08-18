<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

// Indicateurs moyens (30 derniers jours, toutes communes confondues)
$stats = $pdo->query("
    SELECT
        AVG(temperature) AS temp_moy,
        AVG(precipitation) AS pluie_moy,
        AVG(humidite) AS humidite_moy
    FROM climat
    WHERE date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
")->fetch();

$totalCas30j = (int) $pdo->query("
    SELECT COALESCE(SUM(nb_cas), 0)
    FROM sante
    WHERE date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
")->fetchColumn();

// Liste des communes qui ont au moins une donnée climatique (pour le filtre)
$communes = $pdo->query("
    SELECT DISTINCT commune FROM climat ORDER BY commune LIMIT 500
")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="h3 mb-4">Tableau de bord</h1>

<!-- Cartes indicateurs -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="card indicator-card text-center">
      <div class="card-body">
        <div class="text-muted small">Température moyenne</div>
        <div class="fs-2 fw-bold"><?= $stats['temp_moy'] !== null ? round($stats['temp_moy'], 1) . '°C' : '—' ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card indicator-card text-center">
      <div class="card-body">
        <div class="text-muted small">Précipitations</div>
        <div class="fs-2 fw-bold"><?= $stats['pluie_moy'] !== null ? round($stats['pluie_moy']) . ' mm' : '—' ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card indicator-card text-center">
      <div class="card-body">
        <div class="text-muted small">Humidité</div>
        <div class="fs-2 fw-bold"><?= $stats['humidite_moy'] !== null ? round($stats['humidite_moy']) . ' %' : '—' ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card indicator-card text-center">
      <div class="card-body">
        <div class="text-muted small">Cas signalés (30j)</div>
        <div class="fs-2 fw-bold"><?= $totalCas30j ?></div>
      </div>
    </div>
  </div>
</div>

<!-- Filtre commune -->
<div class="mb-3">
  <label for="selectCommune" class="form-label">Commune :</label>
  <select id="selectCommune" class="form-select w-auto d-inline-block">
    <option value="">Toutes les communes</option>
    <?php foreach ($communes as $c): ?>
      <option value="<?= htmlspecialchars($c['commune']) ?>"><?= htmlspecialchars($c['commune']) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<!-- Graphiques -->
<div class="row g-4">
  <div class="col-lg-6">
    <div class="card"><div class="card-body">
      <h2 class="h6">Évolution des températures</h2>
      <canvas id="chartTemperature"></canvas>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card"><div class="card-body">
      <h2 class="h6">Évolution des cas de maladies</h2>
      <canvas id="chartMaladies"></canvas>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card"><div class="card-body">
      <h2 class="h6">Comparaison climat / santé</h2>
      <canvas id="chartComparaison"></canvas>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card"><div class="card-body">
      <h2 class="h6">Tendances mensuelles</h2>
      <canvas id="chartTendances"></canvas>
    </div></div>
  </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/dashboard.js"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
