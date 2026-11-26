<?php use App\Http\View; ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<meta name="csrf-token" content="<?= View::e($csrf) ?>">
<title><?= View::e($title) ?> · Climat &amp; Santé</title>
<link rel="icon" href="<?= View::asset('favicon.svg') ?>" type="image/svg+xml">
<?php if (($path ?? '') === '/carte'): ?><link rel="stylesheet" href="<?= View::asset('vendor/leaflet.css') ?>"><?php endif; ?>
<link rel="stylesheet" href="<?= View::asset('css/app.css') ?>">
</head>
<body data-page="<?= View::e($view) ?>">
<?php if ($withShell): ?>
<a class="skip" href="#contenu">Aller au contenu</a>
<div id="barre" class="topbar-progress" aria-hidden="true"></div>
<div class="shell">
  <aside class="sidebar" id="menu">
    <a class="brand" href="/"><img src="<?= View::asset('favicon.svg') ?>" alt="" width="32" height="32"><span>Climat &amp; Santé<small>Madagascar</small></span></a>
    <nav aria-label="Navigation principale">
      <?php
      $links = [
          '/' => ['Tableau de bord', 'M3 13h8V3H3zm0 8h8v-6H3zm10 0h8V11h-8zm0-18v6h8V3z'],
          '/carte' => ['Carte des risques', 'M12 2a7 7 0 0 0-7 7c0 5 7 13 7 13s7-8 7-13a7 7 0 0 0-7-7zm0 9.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5z'],
          '/communes' => ['Communes', 'M4 6h2v2H4zm4 0h12v2H8zm-4 5h2v2H4zm4 0h12v2H8zm-4 5h2v2H4zm4 0h12v2H8z'],
          '/import-climat' => ['Import climat', 'M19.4 10A7.5 7.5 0 0 0 5.3 8 6 6 0 0 0 6 20h13a5 5 0 0 0 .4-10zM12 17l-4-4h2.5V9h3v4H16z'],
          '/import-dhis2' => ['Import DHIS2', 'M12 3C7.6 3 4 4.3 4 6s3.6 3 8 3 8-1.3 8-3-3.6-3-8-3zM4 9v4c0 1.7 3.6 3 8 3s8-1.300 8-3V9c-1.700 1.300-4.600 2-8 2s-6.300-.7-8-2zm0 7v2c0 1.700 3.600 3 8 3s8-1.300 8-3v-2c-1.700 1.300-4.600 2-8 2s-6.300-.700-8-2z'],
      ];
      foreach ($links as $href => [$label, $icon]): ?>
        <a href="<?= $href ?>" <?= ($path ?? '') === $href ? 'aria-current="page"' : '' ?>>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="<?= $icon ?>"/></svg><?= View::e($label) ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <form method="post" action="/logout" class="account" id="deconnexion">
      <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
      <span>Connecté : <strong><?= View::e($user) ?></strong></span>
      <button type="submit" class="btn btn-ghost">Se déconnecter</button>
    </form>
  </aside>
  <div class="main">
    <header class="mobilebar">
      <button type="button" class="btn btn-ghost" id="ouvrirMenu" aria-controls="menu" aria-expanded="false" aria-label="Ouvrir le menu">☰</button>
      <strong>Climat &amp; Santé</strong>
    </header>
    <main id="contenu" tabindex="-1">
      <div id="alerte" class="banner" role="status" hidden></div>
      <noscript><div class="banner">Cette application nécessite JavaScript.</div></noscript>
      <?= $content ?>
    </main>
    <footer>Données climatiques : NASA POWER · Données sanitaires : DHIS2</footer>
  </div>
</div>
<div id="toasts" class="toasts" aria-live="polite"></div>
<?php if ($view === 'dashboard'): ?><script src="<?= View::asset('vendor/chart.umd.js') ?>" defer></script><?php endif; ?>
<?php if ($view === 'carte'): ?><script src="<?= View::asset('vendor/leaflet.js') ?>" defer></script><?php endif; ?>
<script src="<?= View::asset('js/app.js') ?>" defer></script>
<script src="<?= View::asset("js/$view.js") ?>" defer></script>
<?php else: ?>
<?= $content ?>
<?php if ($view === 'login'): ?><script src="<?= View::asset('js/login.js') ?>" defer></script><?php endif; ?>
<?php endif; ?>
</body>
</html>
