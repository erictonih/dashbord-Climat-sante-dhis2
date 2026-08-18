<?php require_once __DIR__ . '/../config/config.php'; ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Climat &amp; Santé — Tableau de bord</title>

<!-- Bootstrap (mise en page rapide, léger) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">

<!-- Leaflet (carte) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

<!-- Icônes -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

<!-- CSS du projet -->
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark px-3">
  <a class="navbar-brand" href="<?= BASE_URL ?>/index.php">🌍 Climat &amp; Santé</a>
  <div class="collapse navbar-collapse">
    <ul class="navbar-nav me-auto">
      <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/pages/dashboard.php">Tableau de bord</a></li>
      <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/pages/carte.php">Carte</a></li>
      <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/pages/communes.php">Communes</a></li>
      <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/pages/climat.php">Import climat</a></li>
      <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/pages/dhis2.php">Import DHIS2</a></li>
    </ul>
  </div>
</nav>

<main class="container-fluid py-4">
