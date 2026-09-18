# Architecture du socle

## Portée de TASK-0001

L’application est un monolithe modulaire Symfony 7.4 exécuté en PHP 8.4, avec PostgreSQL 17. TASK-0001 définit les frontières, les contrats et le stockage initial sans implémenter les recherches métier. Le seul contrôleur fonctionnel est `GET /health`, qui indique uniquement la vivacité du processus et ne prétend pas tester la base ou des fournisseurs.

## Modules

- `Controller/Api` : adaptateurs HTTP; aucun contrôleur métier avant les tâches dédiées.
- `Provider` : ports `PlaceProvider`, `JourneyProvider` et `AccommodationProvider`; les futurs adaptateurs externes devront respecter ces interfaces et traduire leurs pannes en `ProviderUnavailable`.
- `Trip` et `Accommodation` : requêtes et résultats indépendants du transport HTTP.
- `Environmental` : port d’accès aux facteurs, sans facteur embarqué ni calcul implicite.
- `Http/ProblemListener` : erreurs HTTP au format `application/problem+json`, sans trace, chemin système ou message interne.

Les interfaces empêchent de coupler le domaine à un fournisseur précis. Aucun fallback silencieux de données réelles vers les fixtures `demo` n’est autorisé.

## Persistance

La migration initiale crée sept tables vides : sources, lieux, scénarios, étapes, facteurs, hébergements et preuves environnementales. Les clés étrangères, contraintes de domaine et statuts rendent l’origine et l’incertitude explicites. Elle ne charge aucune fixture.

Le test de migration utilise un schéma PostgreSQL aléatoire, vérifie `up`, l’absence de lignes et `down`, puis supprime uniquement ce schéma. Il interroge explicitement `current_schema()` : DBAL liste volontairement toutes les tables non système de la base et qualifie celles hors schéma courant; son inventaire global ne doit donc pas servir à prouver l’isolation.

## Frontend

Twig rend le HTML et `importmap.php` expose l’entrée `assets/app.js`. Stimulus est chargé par Symfony UX sans Node ni bundler. `asset-map:compile` constitue le contrôle d’intégrité; `public/assets/` et les téléchargements `assets/vendor/` restent générés et ignorés.

## Contrat HTTP

`docs/openapi.yaml` est la source de vérité pour les formes de requête/réponse prévues. Chaque opération porte `x-implementation-status`; seules les opérations marquées `implemented` peuvent être considérées disponibles. Les erreurs publiques suivent Problem Details avec un `code` stable.

Limites du socle : pas de compte, réservation, paiement, cache fournisseur, endpoint métier, ingestion, donnée temps réel ou score écologique global.
