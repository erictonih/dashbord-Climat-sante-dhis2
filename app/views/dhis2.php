<div class="page-head">
  <div><h1>Import DHIS2</h1><p class="muted">Associez les communes et les maladies, puis lancez l'import des données agrégées.</p></div>
  <button type="button" class="btn" id="diagnostic">Tester la connexion</button>
</div>

<section class="card" id="panneauDiagnostic" hidden aria-live="polite"></section>

<section class="grid">
  <article class="card">
    <h2><span class="step">1</span>Communes → unités d'organisation</h2>
    <form class="inline" id="rechercheOU">
      <input name="q" required minlength="2" maxlength="80" placeholder="Rechercher dans DHIS2" aria-label="Rechercher une unité d'organisation">
      <button class="btn">Rechercher</button>
    </form>
    <div id="resultatsOU" class="results" aria-live="polite"></div>
    <h3>Communes associées <span class="badge" id="nbOU">0</span></h3>
    <div class="table-wrap scroll"><table><thead><tr><th>Commune</th><th>Unité DHIS2</th><th><span class="sr">Actions</span></th></tr></thead><tbody id="tableOU"></tbody></table></div>
  </article>
  <article class="card">
    <h2><span class="step">2</span>Maladies → éléments de données</h2>
    <form class="inline" id="rechercheDE">
      <input name="q" required minlength="2" maxlength="80" placeholder="Ex. : paludisme" aria-label="Rechercher un élément de données">
      <button class="btn">Rechercher</button>
    </form>
    <div id="resultatsDE" class="results" aria-live="polite"></div>
    <h3>Maladies associées <span class="badge" id="nbDE">0</span></h3>
    <div class="table-wrap scroll"><table><thead><tr><th>Maladie</th><th>Élément DHIS2</th><th><span class="sr">Actions</span></th></tr></thead><tbody id="tableDE"></tbody></table></div>
  </article>
</section>

<section class="card" id="auto">
  <h2>Association automatique</h2>
  <p class="muted">Seules les correspondances de nom exactes et uniques sont retenues pour les communes.</p>
  <div class="actions"><button type="button" class="btn" data-start>Lancer sur toutes les communes</button><button type="button" class="btn btn-danger" data-stop hidden>Arrêter</button></div>
  <div class="job" data-job hidden><progress></progress><p data-status aria-live="polite"></p><div class="log" data-log></div></div>
</section>

<form class="card" id="formImport">
  <h2><span class="step">3</span>Importer les données</h2>
  <div class="filters">
    <label>Mois de début <input type="month" name="debut" required></label>
    <label>Mois de fin <input type="month" name="fin" required></label>
    <label>Communes par lot <input type="number" name="limit" value="20" min="1" max="100"></label>
  </div>
  <div class="actions">
    <button type="submit" class="btn btn-primary">Lancer l'import</button>
    <button type="button" class="btn btn-danger" data-stop hidden>Arrêter</button>
  </div>
  <div class="job" data-job hidden><progress max="100" value="0"></progress><p data-status aria-live="polite"></p><div class="log" data-log></div></div>
</form>

<dialog id="dialogueMap">
  <form id="formMap">
    <h2 id="titreMap">Associer</h2>
    <p class="muted" id="nomMap"></p>
    <label id="libelleMap"><span></span><input name="valeur" required maxlength="150" list="suggestions" autocomplete="off"></label>
    <datalist id="suggestions"></datalist>
    <div class="actions"><button type="button" class="btn" id="annulerMap">Annuler</button><button type="submit" class="btn btn-primary">Associer</button></div>
  </form>
</dialog>
