<div class="page-head"><div><h1>Import climat</h1><p class="muted">Données quotidiennes NASA POWER, importées par petits lots enchaînés automatiquement.</p></div></div>
<form class="card" id="formClimat">
  <div class="filters">
    <label>Date de début <input type="date" name="debut" required></label>
    <label>Date de fin <input type="date" name="fin" required></label>
    <label>Communes par lot <input type="number" name="limit" value="20" min="1" max="50"></label>
    <label>Une seule commune (facultatif) <input name="commune" maxlength="150" placeholder="Ex. : Ambositra I"></label>
  </div>
  <label class="check"><input type="checkbox" name="mappees" value="1" checked> Seulement les communes associées à DHIS2</label>
  <div class="actions">
    <button type="submit" class="btn btn-primary">Lancer l'import</button>
    <button type="button" class="btn btn-danger" data-stop hidden>Arrêter</button>
  </div>
  <div class="job" data-job hidden>
    <progress max="100" value="0"></progress>
    <p data-status aria-live="polite"></p>
    <div class="log" data-log></div>
  </div>
</form>
