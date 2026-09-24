# Sources de données

## ADEME Base Carbone® — facteurs d’émission optionnels

- Autorité : ADEME ; catalogue <https://data.ademe.fr/datasets/base-carboner> ; API de métadonnées <https://data.ademe.fr/data-fair/api/v1/datasets/base-carboner> ; export <https://data.ademe.fr/data-fair/api/v1/datasets/base-carboner/full>.
- Publication épinglée : V23.6, 18 616 enregistrements, mise à jour portail 2026-06-30, accès 2026-09-24, fréquence annoncée irrégulière.
- Licence : Licence Ouverte / Open Licence (Etalab).
- Snapshot qualifié : 10 761 452 octets, SHA-256 `01472bc24743c0265b649407508dfce896f15a5c11c0f612f6b47a5625b02653`, CSV `;` Windows-1252 avec décimales françaises.
- Sélection : identifiant `28000` seulement; valeur publiée `0,151 kgCO2e/passager.km`; source amont « UTP - Enquête TCU 2017 ». La ligne totale et ses postes carburant/fabrication justifient le périmètre `life_cycle`.
- Applicabilité démographique du libellé « agglomération de plus de 250 000 habitants » : l’[INSEE, comparateur officiel, EPCI 243700754](https://www.insee.fr/fr/statistiques/1405599?geo=EPCI-243700754) publie **301 339 habitants en 2023** pour Tours Métropole Val de Loire (consulté le 2026-09-24). Le seuil est donc satisfait et le facteur est mappé explicitement à `FR-TM`, comme moyenne de cette classe démographique, jamais comme mesure propre à Fil Bleu. Si cette qualification ou les autres attributs épinglés divergent, l’import échoue au lieu d’activer un mapping implicite.

L’import est local, transactionnel, versionné et épinglé par checksum; aucune recherche ne contacte ADEME. L’historique est additif. Un remplacement consiste à qualifier puis importer une **nouvelle** version/date effective, contrôler sa provenance et activer explicitement le provider; il ne faut ni écraser ni supprimer les versions précédentes. Voir `docs/methodology.md` et le README pour les commandes. La fréquence de contrôle suit chaque publication ADEME (irrégulière).

Le fournisseur par défaut reste `demo`. Même en mode `ademe`, les trajets Fil Bleu conservent une émission indisponible car leur distance est inconnue.

## Fil Bleu / Tours Métropole — lieux et horaires théoriques optionnels

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

L'import commun conserve aussi routes, services, exceptions, trips et stop_times dans un snapshot horaire immuable. La recherche locale couvre seulement les bus `route_type=3` directs entre points physiques enfants de stations, avec calendrier, exceptions, règles pickup/drop-off et horaires `>24h` en `Europe/Paris`. Elle ne fait aucun appel réseau. Limites : aucun temps réel, correspondance, train, prix, réservation, distance calculée ou émission carbone. Voir le README pour le téléchargement, la vérification et le rollback transactionnel.

## État actuel

Fil Bleu est la seule source réelle intégrée et demeure optionnelle pour les lieux et trajets directs. Par défaut, aucune donnée métier n'est chargée en base et tous les fournisseurs restent démo. Les objets `demo-*` de `docs/examples/` sont synthétiques : ils servent uniquement à stabiliser le contrat et ne doivent jamais être affichés comme horaires, disponibilités, prix, labels ou performances environnementales réels. La source déclarative `synthetic-tests`, clairement marquée `demo`, n’existe que dans les tests arithmétiques. L’adaptateur d’hébergements reste explicitement synthétique et hors ligne.

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
