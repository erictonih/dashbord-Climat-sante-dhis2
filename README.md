# Climat & Santé — Squelette de projet

Application web PHP/MySQL/JS pour corréler données climatiques (API NASA POWER)
et données de santé publique, avec tableau de bord Chart.js et carte Leaflet.

## Structure du projet

```
climat-sante/
├── config/
│   ├── config.php        # Constantes (DB, API NASA POWER, seuils de risque)
│   └── db.php             # Connexion PDO
├── includes/
│   ├── header.php / footer.php  # Layout commun
│   └── functions.php      # Fonctions utilitaires (dont Pearson)
├── pages/
│   ├── dashboard.php      # Tableau de bord (indicateurs + graphiques)
│   ├── carte.php          # Carte Leaflet
│   ├── communes.php       # Gestion des communes
│   └── climat.php         # Déclenchement manuel de l'import climatique
├── api/
│   ├── import_climat.php  # Import NASA POWER -> Table climat
│   ├── correlation.php    # Calcul du coefficient de Pearson
│   ├── get_communes.php   # JSON pour la carte
│   └── get_climat_data.php # JSON pour les graphiques
├── cron/
│   └── cron_import.php    # Point d'entrée pour webcron externe
├── assets/
│   ├── css/style.css
│   └── js/{main,dashboard,map}.js
├── sql/schema.sql         # Structure de la base de données
└── index.php               # Redirection vers le dashboard
```

## ⚠️ Adapté à ta structure de base réelle

Ce squelette a été ajusté pour correspondre exactement à ta base `if0_42321663_analyse` déjà en place
(1742 communes importées) :
- `communes` (id, code, nom, district, region, latitude, longitude)
- `climat` (id, **commune** [nom, pas d'id !], date, temperature, precipitation, pluie, humidite, vent, rayonnement)
- `sante` (id, **commune** [nom], date, maladie, nb_cas)
- `prediction_epidemie` (id, commune_id, date_prediction, maladie, probabilite, niveau) — prévue pour l'étape 6

Le code PHP fait donc les jointures climat/sante **par nom de commune**, pas par identifiant.
Le fichier `sql/schema.sql` original (avec commune_id) ne correspond plus à ta base réelle —
ignore-le ou utilise-le seulement comme référence si tu repars de zéro un jour.

La table `correlations` est créée automatiquement (si absente) au premier appel à `api/correlation.php`.

## Import santé DHIS2 : un seul appel par dataSet (pas par maladie)

L'import utilise `GET /api/dataValueSets.json?dataSet=DATASET_UID&period=PERIOD&orgUnit=ORGUNIT_UID`,
qui ramène **toutes** les valeurs du dataSet en un seul appel, plutôt que d'interroger chaque
maladie séparément (`dataElement=...`). Beaucoup plus rapide sur un grand nombre de communes.

### ⚠️ Éviter les timeouts InfinityFree (erreur "Unexpected token '<'")

Si tu vois une erreur `Unexpected token '<'` lors de l'import, ce n'est pas un bug JSON : c'est
qu'InfinityFree a **tué le script PHP en cours d'exécution** (temps d'exécution dépassé) et a
renvoyé une page d'erreur HTML au lieu du JSON attendu. Ça arrive vite si tu demandes trop de
communes × trop de mois en un seul clic (ex: 50 communes × 6 mois = 300 appels DHIS2 dans UNE
requête PHP).

**Solution : utilise le bouton "Importer automatiquement" dans `pages/dhis2.php`**, pas
"Importer ce lot". Il enchaîne lui-même, depuis le navigateur, une requête PHP légère à la fois
(un seul mois × un petit nombre de communes), en boucle jusqu'à couvrir toute la période — chaque
requête individuelle reste donc rapide et ne peut pas timeout. Un bouton "Arrêter" permet
d'interrompre l'import à tout moment.

Le bouton "Importer ce lot" (une seule requête manuelle) reste utile pour du débogage ponctuel,
mais évite-le pour de gros volumes.

**Configuration requise** : renseigne `DHIS2_DATASET_UID` dans `config/config.php`. Pour le
trouver, utilise `pages/dhis2.php` → "Tester la connexion DHIS2" → clique sur un des jeux de
données listés pour voir ses éléments et confirmer qu'il contient bien tes maladies suivies.

Fichiers concernés :
- `includes/dhis2_client.php` — `dhis2GetDataValueSet()` (1 appel = tout le dataSet) et
  `dhis2SommeParDataElement()` (somme les catégories d'un même data element)
- `api/import_sante_dhis2.php` — 1 appel réseau par (commune, période), puis filtre/somme
  uniquement les data elements mappés à une maladie (`dhis2_de_mapping`)
- `api/dhis2_test_connexion.php` — accepte `?dataset=UID` pour inspecter le contenu d'un dataSet
  précis avant de le configurer

## Import des données de santé depuis DHIS2

Nouveau module (`pages/dhis2.php`) qui alimente la table `sante` depuis une instance DHIS2 :

1. **Configurer la connexion** dans `config/config.php` : `DHIS2_BASE_URL`, `DHIS2_USERNAME`, `DHIS2_PASSWORD`
   (par défaut pointé sur l'instance de démo publique `play.dhis2.org/demo`, à remplacer par votre instance réelle).
2. **Mapper des communes** : rechercher une unité d'organisation DHIS2 par nom et l'associer
   au nom exact d'une commune de la table `communes`.
3. **Mapper des maladies** : rechercher un élément de donnée DHIS2 (ex: "Malaria confirmed cases")
   et lui donner un nom de maladie libre (ex: `paludisme`) — c'est ce nom qui sera utilisé dans
   la table `sante.maladie` et dans le module de corrélation.
4. **Lancer l'import par lots** (par nombre de communes, comme pour le climat) sur une plage de
   périodes mensuelles DHIS2 (format AAAAMM).

Fichiers concernés :
- `includes/dhis2_client.php` — client HTTP (auth basique, recherche org units/data elements, lecture des valeurs agrégées)
- `api/dhis2_search_orgunits.php`, `api/dhis2_search_dataelements.php` — recherche dans DHIS2
- `api/dhis2_mapping.php` — CRUD des correspondances (tables `dhis2_ou_mapping`, `dhis2_de_mapping`, créées automatiquement)
- `api/import_sante_dhis2.php` — import effectif vers la table `sante`

**Note sur la granularité** : les données NASA POWER sont quotidiennes, les données DHIS2 sont
mensuelles. `api/correlation.php` calcule donc la corrélation au niveau du **mois** (moyenne
climatique mensuelle vs total de cas mensuel), pas du jour.

## Automatisation quotidienne (mapping + climat + santé)

Trois scripts dans `cron/`, protégés par un jeton (`CRON_TOKEN` dans `config/config.php`,
**change la valeur par défaut**), à programmer chacun sur un service de webcron externe et
gratuit comme [cron-job.org](https://cron-job.org) (InfinityFree n'a pas de vrai cron serveur) :

| Script | Rôle | Fréquence conseillée |
|---|---|---|
| `cron/cron_mapping.php?token=...&limit=100&offset=0` | Mappe automatiquement les nouvelles communes (par nom exact, au niveau `DHIS2_NIVEAU_COMMUNE`) et les maladies (`config/dhis2_maladies.php`) non encore mappées | 1×/jour |
| `cron/cron_climat.php?token=...&limit=200&offset=0` | Importe la météo NASA POWER des 3 derniers jours, **uniquement pour les communes déjà mappées à DHIS2** | 1×/jour |
| `cron/cron_sante.php?token=...&limit=200&offset=0` | Importe les données DHIS2 du mois en cours + mois précédent (pour capter les corrections tardives) | 1×/jour |

**Avant d'activer le mapping automatique**, détermine `DHIS2_NIVEAU_COMMUNE` dans
`config/config.php` en utilisant `api/dhis2_test_connexion.php` (voir le tableau des niveaux
hiérarchiques renvoyé). Le mapping automatique ne mappe que les correspondances **exactes et
non ambiguës** — les cas incertains (plusieurs unités du même nom au même niveau, aucun
résultat, etc.) restent listés dans la réponse JSON pour un mapping manuel via `pages/dhis2.php`.

Si tu as plus de communes/maladies que ce que `limit` couvre en une fois, programme plusieurs
appels quotidiens à `cron_mapping.php` avec des `offset` différents, ou augmente `limit` (au prix
d'un temps d'exécution plus long — attention aux limites d'InfinityFree, généralement ~30-60s).

`cron/cron_import.php` (existant) reste disponible pour importer la météo de **toutes** les
communes géolocalisées, sans filtre DHIS2, si besoin.

## Installation sur InfinityFree

1. Ta base MySQL existe déjà (`if0_42321663_analyse`) — pas besoin de réimporter `schema.sql`.
2. Modifier `config/config.php` si besoin (host/nom/user déjà pré-remplis, mets ton vrai mot de passe dans `DB_PASS`).
3. **Uploader** tout le contenu du dossier via FTP dans `htdocs/`.
4. Vérifier que **cURL** est actif (activé par défaut chez InfinityFree) pour l'appel à l'API NASA POWER.
5. Tester l'import manuel via la page **Import climat** (`pages/climat.php`) — **utilise l'import par lots**
   (20 à 50 communes à la fois), car importer les 1742 communes d'un coup dépasserait le temps
   d'exécution maximal autorisé par InfinityFree.

## Automatisation de l'import (webcron)

InfinityFree ne propose pas de vrais cron jobs. Utilisez un service gratuit comme
[cron-job.org](https://cron-job.org) pour appeler périodiquement :

```
https://votresite.infinityfreeapp.com/climat-sante/cron/cron_import.php?token=VOTRE_TOKEN_SECRET
```

Pensez à changer `CRON_TOKEN` dans `cron/cron_import.php`.

## Prochaines étapes

- **Étape 3** : le module `api/correlation.php` calcule déjà le coefficient de Pearson
  climat ↔ maladies ; à brancher sur une page de visualisation dédiée.
- **Étape 5** : la table `risques` doit être alimentée par un script qui calcule
  l'indice de risque (ex. pondération des corrélations + valeurs climatiques du jour).
  À créer : `api/calcul_risque.php`.
- **Étape 6** : une fois 12-24 mois d'historique disponibles, envisager un modèle de
  prédiction (régression, ou API externe de ML) alimenté par les données des tables
  `climat`, `maladies` et `correlations`.

## Bibliothèques utilisées (toutes via CDN, compatibles InfinityFree)

- Bootstrap 5 (mise en page)
- Chart.js 4 (graphiques)
- Leaflet 1.9 (carte)
- Bootstrap Icons
