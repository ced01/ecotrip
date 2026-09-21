# Ecotrip — socle et contrats

Socle technique d’un démonstrateur de comparaison de voyages. TASK-0001 fournit Symfony 7.4/PHP 8.4, PostgreSQL 17, Twig/Stimulus via AssetMapper, la migration initiale et un contrat OpenAPI. TASK-0002 ajoute l’estimation CO₂e auditable par étape. TASK-0003 implémente `capabilities`, le catalogue de lieux et la recherche de trajets de démonstration; l’hébergement reste planifié.

Les données de `docs/examples/` sont entièrement synthétiques, hors ligne et destinées au développement d’interface. Elles ne prouvent ni horaire, disponibilité, prix, label, facteur environnemental, ni offre d’un fournisseur réel.

## Prérequis

- Docker avec Docker Compose v2 ;
- ports locaux `18081` (HTTP) et Docker disponibles ;
- aucun PHP ou Composer hôte requis.

Les versions de l’image PHP, de Composer et de PostgreSQL sont fixées dans `Dockerfile` et `compose.yaml`; les dépendances PHP sont verrouillées par `composer.lock`.

## Installation reproductible

```bash
# Crée .env.local avec des secrets aléatoires, sans l’écraser s’il existe.
docker run --rm -v "$PWD:/app" -w /app php:8.4-cli php tools/setup-local.php

docker compose --env-file .env.local build --pull
docker compose --env-file .env.local run --rm app composer install \
  --no-interaction --prefer-dist --no-progress
docker compose --env-file .env.local run --rm app php bin/console importmap:install
docker compose --env-file .env.local up -d database
docker compose --env-file .env.local run --rm app php bin/console doctrine:migrations:migrate --no-interaction
docker compose --env-file .env.local up -d app
```

`.env.local`, les caches, les dépendances et les assets compilés sont ignorés par Git. Ne jamais committer de secrets. Pour changer le port HTTP, ajouter par exemple `HTTP_PORT=18082` à `.env.local`.

## Vérification

```bash
docker compose --env-file .env.local run --rm app composer check
docker compose --env-file .env.local run --rm app composer validate --strict
docker compose --env-file .env.local run --rm app composer audit
docker compose --env-file .env.local run --rm app php bin/console importmap:audit

curl -i http://127.0.0.1:18081/health
curl -i http://127.0.0.1:18081/api/v1/methodology
curl -i http://127.0.0.1:18081/api/v1/capabilities
curl -i 'http://127.0.0.1:18081/api/v1/places?q=lyon'
curl -i -H 'Content-Type: application/json' -d '{"originId":"demo-paris","destinationId":"demo-lyon","departureDate":"2027-01-15","returnDate":null,"travelers":1,"modes":["train","coach"]}' http://127.0.0.1:18081/api/v1/journeys/search
```

Résultats attendus : les routes implémentées retournent `200 application/json`; `/api/v1/accommodations`, encore non implémenté, retourne `404 application/problem+json` sans détail interne. Les trajets sont toujours marqués `demo`, les deux directions sont calculées séparément et aucune panne ne déclenche de fallback silencieux. `composer check` exécute PHPUnit, les lints conteneur/Twig/YAML, compile AssetMapper et valide le contrat avec ses six exemples JSON.

### Quota et adresse cliente

`POST /api/v1/journeys/search` est limité à 20 requêtes par minute et par adresse cliente. Le compteur Symfony RateLimiter est partagé entre requêtes et workers par le pool cache fichier, avec verrou inter-processus; une réponse dépassant le quota est un `429 application/problem+json` et indique le délai restant dans `Retry-After`. Pour un déploiement multi-hôte, remplacer ce pool par un cache partagé (par exemple Redis) tout en conservant le même limiteur.

Par défaut, aucun proxy n’est approuvé : Symfony utilise l’adresse du pair TCP. Derrière un reverse proxy, configurer explicitement `framework.trusted_proxies` et `framework.trusted_headers` avec les seules adresses/plages du proxy administré avant d’utiliser `X-Forwarded-For`. Ne jamais approuver tous les proxies, sans quoi un client pourrait choisir sa clé de quota.

Arrêt sans supprimer les données PostgreSQL :

```bash
docker compose --env-file .env.local down
```

Ne lancer `down -v` ou un rollback de migration qu’après accord explicite : ces opérations peuvent détruire des données.

## Contrats et documentation

- [`docs/openapi.yaml`](docs/openapi.yaml) : contrat OpenAPI 3.0.3 et statut d’implémentation par opération ;
- [`docs/examples/`](docs/examples/) : exemples synthétiques validés contre les schémas ;
- [`docs/architecture.md`](docs/architecture.md) : modules, frontières et conventions ;
- [`docs/data-sources.md`](docs/data-sources.md) : politique de provenance et sources candidates non intégrées ;
- [`docs/methodology.md`](docs/methodology.md) : règles de calcul prévues et limites explicites.

Le contrat est générable avec `python3 tools/build-contract.py`; après génération, exécuter `composer check` et relire le diff. Le script PHP `tools/validate-contract.php` contrôle la structure OpenAPI, les références, les contraintes de schéma utilisées, les six fichiers JSON et leur identité avec les exemples embarqués.
