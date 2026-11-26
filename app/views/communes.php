<div class="page-head">
  <div><h1>Communes</h1><p class="muted" id="totalCommunes">&nbsp;</p></div>
  <button type="button" class="btn btn-primary" id="nouvelle">Ajouter une commune</button>
</div>
<div class="card">
  <label class="search">Rechercher par nom, district ou région
    <input type="search" id="recherche" placeholder="Ex. : Ambositra" maxlength="80">
  </label>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Code</th><th>Nom</th><th>District</th><th>Région</th><th class="num">Latitude</th><th class="num">Longitude</th></tr></thead>
      <tbody id="lignes" aria-live="polite"></tbody>
    </table>
  </div>
  <div class="pager">
    <button type="button" class="btn" id="precedent">← Précédent</button>
    <span id="position" class="muted"></span>
    <button type="button" class="btn" id="suivant">Suivant →</button>
  </div>
</div>
<dialog id="dialogue">
  <form id="formCommune">
    <h2>Ajouter une commune</h2>
    <label>Code <input name="code" maxlength="50"></label>
    <label>Nom <input name="nom" required maxlength="150"></label>
    <label>District <input name="district" maxlength="150"></label>
    <label>Région <input name="region" maxlength="150"></label>
    <div class="row">
      <label>Latitude <input name="latitude" type="number" step="0.000001" min="-90" max="90"></label>
      <label>Longitude <input name="longitude" type="number" step="0.000001" min="-180" max="180"></label>
    </div>
    <div class="actions"><button type="button" class="btn" id="annuler">Annuler</button><button type="submit" class="btn btn-primary">Ajouter</button></div>
  </form>
</dialog>
