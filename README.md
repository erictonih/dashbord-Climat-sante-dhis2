# Climat & Santé

Application PHP / MySQL / JavaScript qui met en relation les données climatiques (NASA POWER)
et sanitaires (DHIS2) par commune : tableau de bord, carte des risques, corrélations, imports.
Déployée sur Vercel (runtime communautaire `vercel-php`).

## ⚠️ Sécurité : à faire immédiatement

Les anciennes versions de ce dépôt contenaient des mots de passe en clair (MySQL, DHIS2, jeton cron).
Ils restent lisibles dans **l'historique Git** :

1. changez le mot de passe du compte DHIS2 et celui de la base MySQL ;
2. passez le dépôt en privé, ou purgez l'historique (`git filter-repo`) après le changement.

## Configuration (variables d'environnement Vercel)

Voir [`.env.example`](.env.example). Après toute modification, redéployez.

| Groupe | Variables |
|---|---|
| Accès au site | `APP_USER`, `APP_PASSWORD_HASH`, `APP_SECRET`, `CRON_SECRET` |
| Base de données | `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_SSL`, `DB_SSL_CA` |
| DHIS2 | `DHIS2_BASE_URL`, `DHIS2_TOKEN` (ou `DHIS2_USERNAME` / `DHIS2_PASSWORD`), `DHIS2_DATASET_UID`, `DHIS2_NIVEAU_COMMUNE` |

Générer un mot de passe chiffré :
`php -r 'echo password_hash("votre-mot-de-passe-long", PASSWORD_DEFAULT), PHP_EOL;'`

## Base de données

La base MySQL d'InfinityFree n'accepte pas les connexions externes : utilisez un hébergeur MySQL
accessible à distance (Aiven, TiDB Cloud, Railway…), de préférence en Europe (la fonction tourne
à Paris, `cdg1`).

1. Exportez l'ancienne base depuis phpMyAdmin et importez-la dans la nouvelle.
2. Sur une base vide, exécutez `sql/schema.sql`. Sur une base existante, ajoutez les index
   indiqués à la fin de ce fichier ; les tables techniques sont créées automatiquement.

## Structure

```
api/index.php        Unique fonction serverless
app/Core             Configuration, authentification, base, entrées / sorties
app/Dhis2            Client HTTP DHIS2 et requêtes métier
app/Services         Tableau de bord, imports, correspondances, tâches planifiées
app/Http             Routes, API JSON, vues
public/assets        CSS, JavaScript, polices et bibliothèques (hébergées localement)
sql/schema.sql       Schéma
tests/               Tests d'intégration avec faux DHIS2 et NASA POWER
```

## API

Toutes les routes `/api/*` exigent une session ; les `POST` exigent l'en-tête `X-CSRF-Token`.
Réponses : `{"success": true, …}` ou `{"success": false, "error": {"code", "message"}}`.

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/api/status` | État de la configuration |
| GET | `/api/dashboard/summary`, `/series`, `/filters` | Tableau de bord |
| GET | `/api/correlation?climat=&maladie=&commune=` | Corrélation mensuelle |
| GET | `/api/map` | Carte |
| GET / POST | `/api/communes` | Liste paginée / ajout |
| GET | `/api/dhis2/overview`, `/dataset`, `/orgunits`, `/dataelements` | Lecture DHIS2 |
| GET / POST | `/api/dhis2/mappings` (+ `/delete`, `/auto`) | Correspondances |
| POST | `/api/import/sante`, `/api/import/climat` | Imports par lots |

Les imports santé envoient **un seul appel `dataValueSets`** pour 20 unités d'organisation et
jusqu'à 12 mois, avec nouvelles tentatives sur les erreurs passagères.

## Tâches planifiées

Déclarées dans `vercel.json` (une fois par jour) : `/cron/mapping`, `/cron/climat`, `/cron/sante`.
Vercel envoie `Authorization: Bearer $CRON_SECRET`. Chaque exécution traite autant de lots que le
temps le permet et reprend le lendemain à l'endroit où elle s'est arrêtée. `/cron/climat-complet`
(toutes les communes géolocalisées) est disponible pour un déclenchement externe.

## Développement local

```bash
APP_ENV=local APP_SECRET=… APP_PASSWORD=… DB_HOST=127.0.0.1 … php -S 127.0.0.1:8000 -t public dev/router.php
./tests/run.sh      # base MySQL « climat_test », utilisateur test/test
```
