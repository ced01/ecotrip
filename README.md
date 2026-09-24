# EcoTrip — démonstrateur hors ligne reproductible

EcoTrip est un MVP Symfony 7.4/PHP 8.4 de comparaison de voyages. Le mode par défaut reste une démonstration synthétique. Un fournisseur optionnel expose toutefois les horaires théoriques directs de bus Fil Bleu importés localement. Il ne représente ni temps réel, disponibilité, prix, réservation, paiement, ni facteur carbone. Il n’existe aucun fallback silencieux entre fournisseurs réel et démo.

## Prérequis et configuration

- Docker Engine et Docker Compose v2 ;
- `18081/tcp` libre (modifiable dans `.env.local`) ;
- aucun PHP, Composer ou Node hôte requis.

Les images principales sont fixées (`php:8.4.19-cli-bookworm`, `composer:2.9.5`, `postgres:17.9-bookworm`, Node de test `22.22.0-bookworm-slim`) et les dépendances PHP sont verrouillées par `composer.lock`.

`.env.example` ne contient que des valeurs volontairement inutilisables en production. Créer des secrets locaux aléatoires, dans un fichier ignoré par Git et appartenant à l’utilisateur hôte :

```bash
docker run --rm --user "$(id -u):$(id -g)" \
  -e LOCAL_UID="$(id -u)" -e LOCAL_GID="$(id -g)" \
  -v "$PWD:/app" -w /app php:8.4.19-cli-bookworm php tools/setup-local.php
```

Le script refuse d’écraser un `.env.local` existant. Ne jamais réutiliser `.env.example`, transmettre `.env.local` ou committer un secret. `TRUSTED_PROXIES` reste vide par défaut ; voir « Sécurité HTTP ».

## Bootstrap production depuis un checkout frais

Le service `app` est l’artefact final : code, dépendances `--no-dev` et AssetMapper sont intégrés à l’image ; aucun bind mount du checkout n’est utilisé.

```bash
export COMPOSE_PROJECT_NAME=ecotrip_fresh       # nom isolé, au choix
docker compose --env-file .env.local config --quiet
docker compose --env-file .env.local build --pull app
docker compose --env-file .env.local up -d database
docker compose --env-file .env.local run --rm app \
  php bin/console doctrine:migrations:migrate --no-interaction
docker compose --env-file .env.local up -d app
docker compose --env-file .env.local ps
python3 tools/smoke-http.py
```

La migration est explicite, transactionnelle et répétable : une seconde exécution doit répondre qu’aucune migration n’est nécessaire. Vérifier l’état sans modifier la base :

```bash
docker compose --env-file .env.local run --rm app php bin/console doctrine:migrations:status
docker compose --env-file .env.local run --rm app php bin/console doctrine:migrations:up-to-date
```

Aucune fixture de base n’est à charger pour le mode démo. Une petite fixture Fil Bleu attribuée est conservée dans `tests/Fixtures/filbleu-minimal/`; elle sert exclusivement aux tests et n'est pas présentée comme un feed complet.

## Catalogue et trajets Fil Bleu optionnels

Les fournisseurs de lieux et trajets restent `demo` par défaut; la page d'accueil, la bannière, les capabilities et les autres domaines restent démo. Chaque fournisseur réel est une activation **explicite et indépendante** :

```bash
curl --fail --location --output /tmp/filbleu.gtfs.zip \
  'https://data.tours-metropole.fr/api/v2/catalog/datasets/horaires-temps-reel-gtfsrt-reseau-filbleu-tmvl/alternative_exports/filbleu_gtfszip'
printf '%s  %s\n' '1d59de1c3fb6f3daba2c0cef0f7b878d268cd1a5093617392711b2dabccc7c22' /tmp/filbleu.gtfs.zip | sha256sum --check --strict
docker compose --env-file .env.local run --rm -v /tmp/filbleu.gtfs.zip:/tmp/filbleu.gtfs.zip:ro app \
  php bin/console app:places:import-filbleu --file=/tmp/filbleu.gtfs.zip \
  --feed-version=10048_164382514 \
  --sha256=1d59de1c3fb6f3daba2c0cef0f7b878d268cd1a5093617392711b2dabccc7c22
```

Définir ensuite `ECOTRIP_PLACE_PROVIDER=filbleu` et `ECOTRIP_JOURNEY_PROVIDER=filbleu` dans `.env.local`, puis recréer le conteneur applicatif. Chaque sélection est indépendante et vaut `demo` par défaut. Une valeur inconnue échoue au démarrage; `filbleu` sans import complet répond explicitement `503`, sans fallback. Un catalogue démo est incompatible avec les IDs attendus par le fournisseur de trajets Fil Bleu et produit `out_of_coverage`. L'import exige un fichier local, un `feed_version` et un checksum attendus. Il accepte explicitement le nom non standard `feed_infos.txt` observé dans ce feed, mais refuse toute structure incomplète, FK logique inconnue, séquence dupliquée, ligne obligatoire invalide ou collision d'identité.

Chaque import est une transaction unique : source, journal, lieux, références, routes, calendriers, exceptions, trips et stop_times sont tous validés ou tous annulés. `stop_times.txt` est lu en streaming et inséré par lots bornés. Réimporter le même snapshot est idempotent pour le catalogue et les IDs, tout en ajoutant une ligne d'audit et un snapshot horaire immuable. Les stations absentes sont désactivées sans suppression; leur réapparition réactive le même ID. Une mise à jour s'effectue avec la nouvelle version et son checksum vérifié, jamais en effaçant le volume.

La première couverture trajet est volontairement étroite : bus urbains `route_type=3`, trajets directs entre points physiques enfants des deux stations, calendrier et exceptions GTFS, pickup/drop-off et heures après minuit (`>24h`) en `Europe/Paris`. Au plus 20 résultats sont triés par départ, durée et identité stable. Les distances restent `null`/`unknown`; les émissions valent `unavailable`, `null` et `comparable=false`. Aucun appel réseau n'est effectué pendant une recherche.

## Développement et contrôles

Le profil `tools` fournit des conteneurs dev/test avec le checkout monté et l’UID/GID de `.env.local`, afin que `vendor/`, `var/` et les assets restent accessibles à l’utilisateur hôte :

```bash
docker compose --env-file .env.local --profile tools build --pull dev test
docker compose --env-file .env.local up -d database
docker compose --env-file .env.local --profile tools run --rm dev \
  composer install --no-interaction --prefer-dist --no-progress
docker compose --env-file .env.local --profile tools run --rm dev php bin/console importmap:install
docker compose --env-file .env.local --profile tools up -d dev  # http://127.0.0.1:18082
```

Contrôle agrégé complet (Frontend, Backend, contrat, assets et audits) :

```bash
./tools/quality.sh
```

Commandes séparées, utiles en diagnostic :

```bash
docker compose --env-file .env.local --profile tools run --rm ui-test       # npm run test:ui
docker compose --env-file .env.local --profile tools run --rm test composer check
docker compose --env-file .env.local --profile tools run --rm test composer check:backend
docker compose --env-file .env.local --profile tools run --rm test composer check:contract
docker compose --env-file .env.local --profile tools run --rm test composer check:assets
docker compose --env-file .env.local --profile tools run --rm test composer validate --strict
docker compose --env-file .env.local --profile tools run --rm test composer audit
docker compose --env-file .env.local --profile tools run --rm test php bin/console importmap:audit
```

`composer check` exécute PHPUnit, les lints conteneur/Twig/YAML, compile AssetMapper, valide OpenAPI et ses exemples, puis contrôle la configuration de livraison. Le contrat se régénère avec `python3 tools/build-contract.py`; le refaire une seconde fois doit laisser Git sans diff.

## Smoke HTTP de l’artefact livré

```bash
BASE_URL=http://127.0.0.1:18081 python3 tools/smoke-http.py
```

Le smoke vérifie réellement l’accueil HTML, `/health`, capabilities, places, méthodologie, hébergements, recherche de trajet, une 404 Problem Details, les rejets `415`/`413`, ainsi que les fichiers CSS et JavaScript compilés et leurs types de média.

## Modes et limites connues

- `capabilities.mode`, l’UI, les résultats et les documents annoncent `demo` ; les provenances valent `demo`.
- `real` désigne le fournisseur Fil Bleu optionnel pour les trajets directs de bus théoriques; capabilities et UI restent volontairement en démo jusqu'à leur tâche d'intégration.
- `unavailable`/`provider_unavailable` est exposé en `503`, jamais remplacé par les fixtures démo.
- Les paires couvertes, 9 voyageurs maximum, corps JSON de 16 KiB, 20 recherches/minute/adresse et absence de réservation sont publiés par `/api/v1/capabilities`.
- Les trains, correspondances, prix, réservation, temps réel, distances et émissions réelles restent hors périmètre.

Voir aussi [`docs/data-sources.md`](docs/data-sources.md), [`docs/methodology.md`](docs/methodology.md), [`docs/architecture.md`](docs/architecture.md) et [`docs/openapi.yaml`](docs/openapi.yaml).

## Sécurité HTTP

L’image finale s’exécute avec l’utilisateur non-root `app` (UID 10001), sans capacités Linux et avec `no-new-privileges`. `APP_ENV=prod` et `APP_DEBUG=0` sont forcés pour l’artefact livré ; le serveur masque l’affichage des erreurs. Les erreurs API publiques sont des `application/problem+json` génériques sans exception, chemin système, requête ni secret. Ne jamais mettre de secret ou donnée personnelle dans URL, identifiant de lieu ou logs.

Le quota utilise l’adresse du pair TCP. Aucun proxy n’est approuvé par défaut. Derrière un reverse proxy administré, définir `TRUSTED_PROXIES` à ses seules IP/plages CIDR (séparées par des virgules) ; les en-têtes `Forwarded` ne sont alors acceptés que pour ce pair. Ne jamais utiliser `0.0.0.0/0` ou `REMOTE_ADDR` sans maîtriser le réseau. En multi-hôte, remplacer le cache fichier du rate limiter par un stockage partagé.

Le healthcheck teste `/health` depuis le conteneur. Il prouve que le processus HTTP répond, pas qu’un fournisseur réel existe.

## Arrêt et dépannage

Arrêter **sans supprimer les données** :

```bash
docker compose --env-file .env.local down
```

Ne jamais utiliser `down -v`, supprimer le volume ou revenir en arrière sur une migration sans accord explicite et sauvegarde.

Diagnostics non destructifs :

```bash
docker compose --env-file .env.local ps
docker compose --env-file .env.local logs --no-log-prefix app database
docker inspect --format '{{.State.Health.Status}} {{.Config.User}}' ecotrip-app-1
docker compose --env-file .env.local config
```

- **Permission refusée sur `.env.local`, `vendor/` ou `var/`** : vérifier `LOCAL_UID=$(id -u)` et `LOCAL_GID=$(id -g)` ; recréer uniquement les fichiers générés après en avoir sauvegardé le contenu utile. Le générateur doit être lancé avec `--user` comme ci-dessus.
- **Port occupé** : changer `HTTP_PORT` (production) ou `DEV_HTTP_PORT` (développement) dans `.env.local`.
- **Service unhealthy** : consulter `docker compose ... logs app`, puis appeler `/health`; vérifier que migrations et secrets ont été fournis.
- **Base indisponible** : attendre le healthcheck PostgreSQL puis consulter `doctrine:migrations:status`.
- **Assets absents** : reconstruire l’image finale, ou exécuter `importmap:install` puis `composer check:assets` dans le service `dev`.
