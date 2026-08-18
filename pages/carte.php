<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="h3 mb-4">Carte des risques par commune</h1>

<div class="mb-3 d-flex gap-3 align-items-center flex-wrap">
  <span><span class="legend-dot" style="background:#28a745"></span> Risque faible</span>
  <span><span class="legend-dot" style="background:#ffc107"></span> Risque moyen</span>
  <span><span class="legend-dot" style="background:#dc3545"></span> Risque élevé</span>
</div>

<div id="carte" style="height: 600px; border-radius: 8px;"></div>

<script src="<?= BASE_URL ?>/assets/js/map.js"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
