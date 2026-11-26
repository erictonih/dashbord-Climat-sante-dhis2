<div class="page-head">
  <div><h1>Tableau de bord</h1><p class="muted" id="contexte">Toutes les communes</p></div>
  <div class="filters" role="group" aria-label="Filtres">
    <label>Commune
      <input id="commune" list="listeCommunes" placeholder="Toutes les communes" autocomplete="off">
    </label>
    <datalist id="listeCommunes"></datalist>
    <label>Période
      <select id="mois">
        <option value="3">3 mois</option><option value="6" selected>6 mois</option>
        <option value="12">12 mois</option><option value="24">24 mois</option><option value="60">5 ans</option>
      </select>
    </label>
    <button type="button" class="btn" id="actualiser">Actualiser</button>
  </div>
</div>

<section class="tiles" aria-label="Indicateurs">
  <article class="card tile" data-tile="temperature"><h2>Température moyenne</h2><p class="value skeleton">—</p><p class="muted sub">&nbsp;</p></article>
  <article class="card tile" data-tile="precipitation"><h2>Précipitations</h2><p class="value skeleton">—</p><p class="muted sub">&nbsp;</p></article>
  <article class="card tile" data-tile="humidite"><h2>Humidité relative</h2><p class="value skeleton">—</p><p class="muted sub">&nbsp;</p></article>
  <article class="card tile" data-tile="cas"><h2>Cas signalés</h2><p class="value skeleton">—</p><p class="muted sub">&nbsp;</p></article>
</section>

<section class="grid">
  <article class="card" data-panel="temperature">
    <h2>Température quotidienne <span class="muted">(°C)</span></h2>
    <div class="chart skeleton"><canvas id="graphTemperature" role="img" aria-label="Courbe de la température quotidienne"></canvas></div>
  </article>
  <article class="card" data-panel="precipitation">
    <h2>Précipitations quotidiennes <span class="muted">(mm)</span></h2>
    <div class="chart skeleton"><canvas id="graphPluie" role="img" aria-label="Histogramme des précipitations quotidiennes"></canvas></div>
  </article>
  <article class="card wide" data-panel="maladies">
    <h2>Cas par mois et par maladie</h2>
    <div class="chart skeleton"><canvas id="graphMaladies" role="img" aria-label="Histogramme empilé des cas mensuels par maladie"></canvas></div>
    <details><summary>Voir le tableau des données</summary><div class="table-wrap" id="tableMaladies"></div></details>
  </article>
  <article class="card wide">
    <h2>Corrélation climat ↔ santé</h2>
    <div class="correlation">
      <div class="filters">
        <label>Variable climatique
          <select id="corrClimat">
            <option value="temperature">Température</option><option value="precipitation">Précipitations</option>
            <option value="humidite">Humidité</option><option value="vent">Vent</option><option value="rayonnement">Rayonnement</option>
          </select>
        </label>
        <label>Maladie <select id="corrMaladie"></select></label>
      </div>
      <div id="corrResultat" aria-live="polite"><p class="muted">Sélectionnez une maladie.</p></div>
    </div>
    <p class="muted small">Coefficient de Pearson mensuel, entre −1 et 1, sur tout l'historique. Une corrélation ne démontre pas un lien de causalité.</p>
  </article>
</section>
