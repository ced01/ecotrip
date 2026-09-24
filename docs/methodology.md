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
- mode : `public_transport`, sous-type générique `null`, géographie EcoTrip `FR-TM` (Tours Métropole) ;
- périmètre : `life_cycle`, car l’élément ADEME est « décomposé par poste » et l’export officiel détaille un poste carburant (amont/combustion) et un poste fabrication ;
- période source : `avr-22` est conservée textuellement. Elle n’est **pas** transformée en date de fin ;
- validité EcoTrip : date effective explicite de publication/import (`2026-06-30` par défaut pour V23.6), sans fin inventée ; une version ultérieure prend effet uniquement à sa propre date explicite ;
- méthode : `ademe-v23.6-explicit-map-v1`, stockée avec les notes, le checksum et toutes les métadonnées sources.

La catégorie démographique est cohérente avec la population officielle de Tours Métropole, mais reste une moyenne UTP de réseau urbain : elle ne constitue pas une mesure spécifique d’un bus ou trajet Fil Bleu. Tout changement de libellé, statut, unité, géographie, structure ou identifiant fait échouer l’import.

## Historique, provenance et contrôles

L’identité historique inclut source, identifiant externe et version de publication. Une réimportation au même checksum renvoie l’import existant sans mutation. Une même version avec un checksum différent, un doublon contradictoire, un identifiant attendu absent, un checksum/encodage/séparateur/colonnes/date/statut/unité/valeur/mapping invalide fait échouer toute la transaction.

Chaque facteur restitue valeur et unité source/normalisées, identifiant, noms, statut, géographie et période sources, mode, périmètre, éventuelle occupation, version, source amont, URL, licence, date d’accès, checksum et notes/méthode de mapping.

## Statuts et comparabilité

`complete`, `partial`, `unavailable` et `demo` restent distincts du statut du trajet. Une estimation n’est comparable que si elle est complète et homogène. La clé v2 encode indicateur, unité, périmètre, provenance trajet, statut facteur, version source et méthode de mapping. Le comparateur refuse les différences de portée, unité, version, méthode ou provenance et toutes les couvertures partielles.

## Sources et limites

- Catalogue ADEME : <https://data.ademe.fr/datasets/base-carboner>
- Export officiel : <https://data.ademe.fr/data-fair/api/v1/datasets/base-carboner/full>
- Métadonnées/API : <https://data.ademe.fr/data-fair/api/v1/datasets/base-carboner>
- Version : V23.6 ; accès : 2026-09-24 ; licence : Licence Ouverte / Open Licence (Etalab)
- Snapshot : 10 761 452 octets ; SHA-256 `01472bc24743c0265b649407508dfce896f15a5c11c0f612f6b47a5625b02653`

La Base Carbone est publiée irrégulièrement. EcoTrip ne met jamais à jour en place : télécharger une nouvelle publication hors application, vérifier sa documentation, choisir une nouvelle version et un checksum, importer additivement avec sa date effective, valider en base, puis seulement modifier la configuration. Ne jamais supprimer l’ancienne version.

La méthode ne constitue ni une ACV complète ni un conseil de réservation. Elle ne couvre pas prix, disponibilité, hébergement, forçage radiatif, effets rebond ni score global. L’incertitude ADEME et la représentativité moyenne doivent rester prises en compte.
