# Méthodologie environnementale

## Statut et version

`carbon-estimation-v2` ajoute des facteurs publiés, versionnés et auditables. L’expérience publique, la page d’accueil, les capabilities et la configuration par défaut restent `demo`. Le dépôt ADEME n’est utilisé qu’avec `ECOTRIP_EMISSION_FACTOR_PROVIDER=ademe`, après import complet; une erreur de configuration ou une base non initialisée échoue explicitement, sans fallback vers les facteurs démo.

## Calcul et inconnues

L’indicateur est le kilogramme de CO₂ équivalent (`kgCO2e`). Pour une distance `d`, un facteur passager-km `f` et `n` voyageurs : individuel = `d × f`, groupe = `d × f × n`. Le moteur conserve les intermédiaires non arrondis, agrège, puis arrondit chaque montant exposé à 12 décimales (`PHP_ROUND_HALF_UP`). Une distance ou émission inconnue vaut `null`, jamais zéro.

Une étape n’est calculée que si le repository retourne exactement un candidat applicable au mode, sous-type, territoire et jour. Zéro candidat (absent, archivé, expiré, incompatible) ou plusieurs candidats (ambiguïté) donnent `unavailable`. Le repository ADEME sélectionne la publication dont la date effective est la plus récente sans dépasser le voyage; une égalité ambiguë reste multiple et donc indisponible. Il ne fait aucun appel réseau.

Fil Bleu fournit actuellement des horaires mais pas de distance. Ses émissions restent donc `unavailable`, y compris lorsque le facteur ADEME est chargé. Le facteur n’invente ni distance ni occupation.

## Mapping ADEME V23.6

L’unique identifiant autorisé est `28000`, ligne `Elément`, statut `Valide générique` : « Autobus moyen — Agglomération de plus de 250 000 habitants », France continentale, `0,151 kgCO2e/passager.km`, source amont « UTP - Enquête TCU 2017 ».

- unité : conversion littérale documentée vers `kgCO2e/passenger-km` ;
- mode : `public_transport`, sous-type explicite `bus_urban`, géographie EcoTrip `FR-TM` (Tours Métropole). Il ne peut donc correspondre ni à un train, ni à un autocar, ni à un transport public sans sous-type ;
- périmètre : `life_cycle`, car les lignes `Poste` officielles et obligatoires valent exactement `Carburant (amont/combustion) = 0,129` et `Fabrication = 0,0225`. Une ligne absente, modifiée ou contradictoire interdit l’activation ;
- période source : `avr-22` est conservée textuellement. Elle n’est **pas** transformée en date de fin ;
- validité EcoTrip : date effective qualifiée `2026-06-30`, analysée strictement au format `YYYY-MM-DD`, sans fin inventée ;
- méthode : `ademe-v23.6-explicit-map-v2`, stockée avec les notes, le checksum et toutes les métadonnées sources.

La catégorie démographique est cohérente avec la population officielle de Tours Métropole, mais reste une moyenne UTP de réseau urbain : elle ne constitue pas une mesure spécifique d’un bus ou trajet Fil Bleu. Tout changement de libellé, statut, unité, géographie, structure ou identifiant fait échouer l’import.

## Historique, provenance et contrôles

L’identité historique inclut source, identifiant externe et version de publication. Une réimportation au même checksum renvoie l’import existant sans mutation. L’import courant refuse toute version autre que V23.6 et tout checksum autre que le snapshot officiel. Une même version avec un checksum différent, un doublon contradictoire, un identifiant attendu absent, une preuve `Poste` incomplète, un checksum/encodage/séparateur/colonnes/date/statut/unité/valeur/mapping invalide fait échouer toute la transaction. Un verrou transactionnel PostgreSQL sérialise deux imports concurrents de la même publication.

Le registre de publications du runtime ne contient actuellement que V23.6. La capacité structurelle multi-version est vérifiée en intégration avec des versions préfixées `SYNTHETIC-`, créées directement dans un schéma de test : l’ancienne ligne reste conservée et la date de voyage sélectionne déterministement la publication qualifiée la plus récente. Ces entrées ne passent jamais par l’importeur, ne sont pas des publications ADEME revendiquées, et le registre de production les refuse; elles démontrent l’historisation sans inventer une V23.7 réelle. À chaque lecture, import et facteur doivent correspondre exactement au registre (source/version/checksum/date effective, éditeur/URL/licence/date d’accès/méthode, valeur, dates et provenance métier complètes). Une publication enregistrée mais absente ou corrompue ferme la sélection au lieu de revenir à une version ou à un facteur démo.

Chaque facteur restitue valeur et unité source/normalisées, identifiant, noms, statut, géographie et période sources, mode, périmètre, éventuelle occupation, version, source amont, URL, licence, date d’accès, checksum et notes/méthode de mapping. URL, licence, date d’accès, checksum et méthode sont lus depuis l’import immuable autoritatif. `selection_status` reste un détail interne de sélection et n’est pas exposé par le schéma API `Factor`.

## Statuts et comparabilité

`complete`, `partial`, `unavailable` et `demo` restent distincts du statut du trajet. Une estimation n’est comparable que si elle est complète et homogène. La clé v2 encode indicateur, unité, périmètre, provenance trajet, statut facteur, version source et méthode de mapping. Le comparateur refuse les différences de portée, unité, version, méthode ou provenance et toutes les couvertures partielles.

## Sources et limites

- Catalogue ADEME : <https://data.ademe.fr/datasets/base-carboner>
- Export officiel : <https://data.ademe.fr/data-fair/api/v1/datasets/base-carboner/full>
- Métadonnées/API : <https://data.ademe.fr/data-fair/api/v1/datasets/base-carboner>
- Version : V23.6 ; accès : 2026-09-24 ; licence : Licence Ouverte / Open Licence (Etalab)
- Snapshot : 10 761 452 octets ; SHA-256 `01472bc24743c0265b649407508dfce896f15a5c11c0f612f6b47a5625b02653`

La Base Carbone est publiée irrégulièrement. EcoTrip ne met jamais à jour en place : télécharger une nouvelle publication hors application, vérifier sa documentation, ajouter et revoir son mapping/version/checksum/date au registre qualifié, importer additivement, valider en base, puis seulement modifier la configuration. Tant que ce travail n’est pas livré, la commande refuse cette publication. Ne jamais supprimer l’ancienne version.

La méthode ne constitue ni une ACV complète ni un conseil de réservation. Elle ne couvre pas prix, disponibilité, hébergement, forçage radiatif, effets rebond ni score global. L’incertitude ADEME et la représentativité moyenne doivent rester prises en compte.
