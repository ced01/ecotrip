# Sources de données et provenance

## État actuel

Le projet n’intègre **aucune source réelle** et ne contient aucune donnée métier en base. Les objets `demo-*` de `docs/examples/` sont synthétiques : ils servent uniquement à stabiliser le contrat et ne doivent jamais être affichés comme horaires, disponibilités, prix, labels ou performances environnementales réels. TASK-0002 ajoute la source déclarative `synthetic-tests`, clairement marquée `demo`; ses facteurs fictifs n’existent que dans les tests arithmétiques et ne sont ni une mesure ni une moyenne publiée.

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

`null` signifie inconnu; `0` représente seulement une valeur réellement nulle. Une déclaration d’un hébergeur ne devient pas une certification. Le statut `verified` d’une preuve exige organisme, référence et date de contrôle.

## Qualité et défaillances

Les données réelles ne basculent jamais silencieusement vers des exemples synthétiques. Une panne totale de fournisseur doit être signalée; une réponse partielle doit identifier ce qui manque. Les données expirées, hors couverture ou de périmètres incompatibles ne doivent pas être classées comme comparables.
