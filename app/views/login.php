<?php use App\Http\View; ?>
<main class="login">
  <form method="post" action="/login" class="card login-card" id="formConnexion">
    <img src="<?= View::asset('favicon.svg') ?>" alt="" width="48" height="48">
    <h1>Climat &amp; Santé</h1>
    <p class="muted">Surveillance climatique et épidémiologique. Accès réservé.</p>
    <?php if ($error): ?><div class="banner banner-error" role="alert"><?= View::e($error) ?></div><?php endif; ?>
    <label>Identifiant
      <input name="utilisateur" autocomplete="username" required maxlength="60" autofocus>
    </label>
    <label>Mot de passe
      <input type="password" name="mot_de_passe" autocomplete="current-password" required maxlength="200">
    </label>
    <button type="submit" class="btn btn-primary">Se connecter</button>
  </form>
</main>
