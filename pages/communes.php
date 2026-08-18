<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

$pdo = getDB();

// Ajout d'une commune
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare('INSERT INTO communes (code, nom, district, region, latitude, longitude) VALUES (:code, :nom, :district, :region, :lat, :lon)');
    $stmt->execute([
        ':code'     => $_POST['code'] ?: null,
        ':nom'      => $_POST['nom'],
        ':district' => $_POST['district'] ?: null,
        ':region'   => $_POST['region'] ?: null,
        ':lat'      => $_POST['latitude'] ?: null,
        ':lon'      => $_POST['longitude'] ?: null,
    ]);
    header('Location: communes.php');
    exit;
}

// Recherche simple (la table contient 1742 communes, on pagine)
$recherche = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$parPage = 50;
$offset = ($page - 1) * $parPage;

if ($recherche !== '') {
    $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM communes WHERE nom LIKE :q OR district LIKE :q OR region LIKE :q');
    $stmtCount->execute([':q' => "%$recherche%"]);
    $total = (int) $stmtCount->fetchColumn();

    $stmt = $pdo->prepare('SELECT * FROM communes WHERE nom LIKE :q OR district LIKE :q OR region LIKE :q ORDER BY nom LIMIT :limit OFFSET :offset');
    $stmt->bindValue(':q', "%$recherche%");
} else {
    $total = (int) $pdo->query('SELECT COUNT(*) FROM communes')->fetchColumn();
    $stmt = $pdo->prepare('SELECT * FROM communes ORDER BY nom LIMIT :limit OFFSET :offset');
}
$stmt->bindValue(':limit', $parPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$communes = $stmt->fetchAll();

$nbPages = (int) ceil($total / $parPage);

require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="h3 mb-4">Communes (<?= $total ?>)</h1>

<div class="row g-4">
  <div class="col-lg-4">
    <div class="card">
      <div class="card-body">
        <h2 class="h6 mb-3">Ajouter une commune</h2>
        <form method="post">
          <div class="mb-2">
            <label class="form-label">Code</label>
            <input type="text" name="code" class="form-control">
          </div>
          <div class="mb-2">
            <label class="form-label">Nom</label>
            <input type="text" name="nom" class="form-control" required>
          </div>
          <div class="mb-2">
            <label class="form-label">District</label>
            <input type="text" name="district" class="form-control">
          </div>
          <div class="mb-2">
            <label class="form-label">Région</label>
            <input type="text" name="region" class="form-control">
          </div>
          <div class="row">
            <div class="col mb-3">
              <label class="form-label">Latitude</label>
              <input type="number" step="0.000001" name="latitude" class="form-control">
            </div>
            <div class="col mb-3">
              <label class="form-label">Longitude</label>
              <input type="number" step="0.000001" name="longitude" class="form-control">
            </div>
          </div>
          <button type="submit" class="btn btn-primary">Ajouter</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card">
      <div class="card-body">
        <form method="get" class="mb-3 d-flex gap-2">
          <input type="text" name="q" class="form-control" placeholder="Rechercher (nom, district, région)" value="<?= htmlspecialchars($recherche) ?>">
          <button type="submit" class="btn btn-outline-secondary">Rechercher</button>
        </form>

        <table class="table table-sm table-striped">
          <thead>
            <tr><th>Nom</th><th>District</th><th>Région</th><th>Lat</th><th>Lon</th></tr>
          </thead>
          <tbody>
            <?php foreach ($communes as $c): ?>
            <tr>
              <td><?= htmlspecialchars($c['nom']) ?></td>
              <td><?= htmlspecialchars($c['district'] ?? '—') ?></td>
              <td><?= htmlspecialchars($c['region'] ?? '—') ?></td>
              <td><?= $c['latitude'] ?? '—' ?></td>
              <td><?= $c['longitude'] ?? '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <?php if ($nbPages > 1): ?>
        <nav>
          <ul class="pagination pagination-sm flex-wrap">
            <?php for ($p = 1; $p <= $nbPages; $p++): ?>
              <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link" href="?q=<?= urlencode($recherche) ?>&page=<?= $p ?>"><?= $p ?></a>
              </li>
            <?php endfor; ?>
          </ul>
        </nav>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
