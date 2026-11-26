<?php use App\Http\View; ?>
<main class="login">
  <div class="card login-card">
    <h1><?= View::e($title) ?></h1>
    <p><?= View::e($message) ?></p>
    <a class="btn btn-primary" href="/">Retour à l'accueil</a>
  </div>
</main>
