# Sources de données

## Fil Bleu / Tours Métropole — catalogue optionnel de lieux réels

- Catalogue officiel : <https://www.data.gouv.fr/datasets/fil-bleu-syndicat-des-mobilites-gtfs-gtfs-rt>
- Artefact : <https://data.tours-metropole.fr/api/v2/catalog/datasets/horaires-temps-reel-gtfsrt-reseau-filbleu-tmvl/alternative_exports/filbleu_gtfszip>
- Éditeur : Tours Métropole Val de Loire ; exploitant/feed publisher : Fil Bleu (Tours).
- Licence déclarée : Licence Ouverte 2.0 (`lov2`). Consultation : 2026-09-23.
- Snapshot qualifié : 5 865 330 octets, SHA-256 `1d59de1c3fb6f3daba2c0cef0f7b878d268cd1a5093617392711b2dabccc7c22`, `feed_version=10048_164382514`, validité 2026-09-11–2026-12-31.
- Couverture : réseau Fil Bleu de Tours Métropole, France. `countryCode=FR` vient de ce périmètre publié. Les coordonnées et le fuseau viennent du feed; aucun géocodage ou rapprochement par nom n'est effectué.
- Le snapshot contient 774 stations commerciales (`location_type=1`) parmi 2 269 arrêts. Seules ces stations sont exposées comme lieux; les points physiques sont conservés comme références non publiques pour les correspondances fournisseur.
- Particularité qualifiée : le feed fournit `feed_infos.txt` au pluriel. L'importeur l'accepte explicitement sans rendre les autres contrôles permissifs.
- Fraîcheur annoncée : au maximum quotidienne (`continuous` au catalogue). EcoTrip importe un fichier local épinglé et n'appelle pas le réseau pendant la saisie.

Les IDs publics sont `filbleu-` suivi des 40 premiers caractères hexadécimaux de `SHA-256("filbleu-gtfs" + NUL + stop_id)`. Cette fonction est opaque, déterministe et indépendante de l'ordre. La table de référence conserve le `stop_id` exact; aucun ID externe brut n'est exposé comme ID EcoTrip. Les suppressions amont désactivent la référence sans suppression ni réaffectation.

Limites : il s'agit d'un référentiel d'arrêts et non d'un géocodeur, d'une promesse de desserte, d'horaires temps réel, d'un prix ou d'une réservation. Le mode réel des trajets n'est pas activé par ce catalogue. Voir le README pour le téléchargement, la vérification, l'import, le remplacement non destructif et le rollback transactionnel.

## État actuel

Le catalogue Fil Bleu est la seule source réelle intégrée et demeure optionnel. Par défaut, aucune donnée métier n'est chargée en base et tous les domaines restent démo. Les objets `demo-*` de `docs/examples/` sont synthétiques : ils servent uniquement à stabiliser le contrat et ne doivent jamais être affichés comme horaires, disponibilités, prix, labels ou performances environnementales réels. La source déclarative `synthetic-tests`, clairement marquée `demo`, n’existe que dans les tests arithmétiques. L’adaptateur d’hébergements reste explicitement synthétique et hors ligne.

## Sources candidates à instruire

Les familles suivantes sont des pistes, pas des fournisseurs sélectionnés ni disponibles :

- référentiels publics de lieux et gares pour l’identification géographique ;
- flux officiels ou sous licence de transport pour offres et horaires ;
- bases publiques de facteurs d’émission publiées par une autorité compétente ;
- catalogues d’hébergement dont les droits de réutilisation et la fraîcheur sont vérifiables ;
- registres officiels des certifications environnementales.

Avant toute intégration, une tâche dédiée doit documenter l’éditeur exact, l’URL, la licence, les conditions de réutilisation, la version, la date d’accès, la couverture, la fréquence de mise à jour et les limites. Aucun nom de fournisseur n’est affirmé ici pour éviter de suggérer une relation ou une disponibilité non vérifiée.

## Exigences de traçabilité

Chaque source persistée doit avoir un identifiant stable, un éditeur, des notes de réutilisation et un statut `demo`, `verified` ou `unverified`. URL, licence, version et date d’accès restent `null` tant qu’elles ne sont pas établies; elles ne sont jamais inventées.

Chaque valeur exposée doit référencer sa ou ses sources et conserver :

- le statut de provenance ;
- la date de validité ou d’observation lorsqu’elle existe ;
- la méthode utilisée pour une distance ou un calcul ;
- les hypothèses et avertissements nécessaires.

`null` signifie inconnu; `0` représente seulement une valeur réellement nulle. Une proximité connue est accompagnée d’une distance mesurée dans la fixture; sinon proximité et distance restent inconnues. Une déclaration d’un hébergeur ne devient pas une certification. Toute certification exige organisme, référence et date de contrôle; une certification synthétique reste `demo`, et une preuve au-delà de sa date de validité devient `expired`.

## Qualité et défaillances

Les données réelles ne basculent jamais silencieusement vers des exemples synthétiques. Une panne totale de fournisseur doit être signalée; une réponse partielle doit identifier ce qui manque. Les données expirées, hors couverture ou de périmètres incompatibles ne doivent pas être classées comme comparables.
