# Fixture GTFS Fil Bleu minimale

Extrait fidèle et attribué du snapshot Fil Bleu/Tours Métropole `feed_version=10048_164382514`, consulté le 23 septembre 2026 (snapshot complet SHA-256 `1d59de1c3fb6f3daba2c0cef0f7b878d268cd1a5093617392711b2dabccc7c22`).

Source catalogue : https://www.data.gouv.fr/datasets/fil-bleu-syndicat-des-mobilites-gtfs-gtfs-rt

Éditeur : Tours Métropole Val de Loire. Exploitant : Fil Bleu (Tours). Réutilisation sous **Licence Ouverte 2.0**.

La fixture conserve les valeurs réellement vérifiées suivantes : ligne bus 2 (`route_type=3`), service semaine du 14 septembre au 19 décembre 2026, trip se terminant par `272179`, départ du point physique `Gare de Tours 6` à **05:22** (séquence 21) et arrivée `Jean Jaurès 3` à **05:24** (séquence 22), le mercredi **2026-09-23**. Elle ne constitue pas un feed complet : seuls ce segment direct et les stations Gare de Tours, Jean Jaurès et Vaucanson sont conservés. Les IDs GTFS servent uniquement à l'import et ne doivent jamais franchir l'API.
