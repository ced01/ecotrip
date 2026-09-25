# Fixture ADEME Base Carbone® V23.6

`selected.csv` est une extraction minimale (ligne `Elément` et ses deux lignes `Poste`, identifiant `28000`) de l’export officiel ADEME Base Carbone® V23.6 téléchargé le 2026-09-24. Les postes officiels `Carburant (amont/combustion)` et `Fabrication` constituent la preuve qualifiée du périmètre cycle de vie; leur absence ou modification interdit l’activation.

- Catalogue : https://data.ademe.fr/datasets/base-carboner
- Export : https://data.ademe.fr/data-fair/api/v1/datasets/base-carboner/full
- Export complet : SHA-256 `01472bc24743c0265b649407508dfce896f15a5c11c0f612f6b47a5625b02653`, 10 761 452 octets
- Licence : Licence Ouverte / Open Licence (Etalab)
- Encodage conservé : Windows-1252; séparateur `;`
- SHA-256 de la fixture dérivée : `5d44847279a21d3e798c0a16a1360a7f93e25d2044b4264bb0fce19695d7a790` (injecté uniquement par les tests; la commande de production n’accepte que le checksum officiel ci-dessus)
- Applicabilité démographique du mapping : l’INSEE publie 301 339 habitants en 2023 pour Tours Métropole Val de Loire (EPCI 243700754) : https://www.insee.fr/fr/statistiques/1405599?geo=EPCI-243700754 (consulté le 2026-09-24).

La fixture n’est pas le snapshot complet et ne sert qu’aux tests reproductibles de sélection et validation. Les cas invalides sont dérivés en fichiers temporaires pendant les tests.
