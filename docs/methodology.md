# Méthodologie environnementale

## Statut

La version contractuelle `foundation-v1` est `unavailable` : aucun facteur environnemental réel n’est intégré ou vérifié dans TASK-0001. Les exemples retournent donc des émissions `null`, `comparable: false` et une raison explicite. Les durées synthétiques ne sont pas des horaires.

## Indicateur et calcul prévu

L’indicateur prévu est le kilogramme de CO₂ équivalent (`kgCO2e`). Un calcul futur devra être effectué par étape et conserver le facteur exact utilisé : valeur, unité, mode/sous-type, géographie, période de validité, périmètre, hypothèse d’occupation, version et source.

Deux unités sont modélisées :

- `kgCO2e/passenger-km` : multiplication par la distance couverte et le nombre de voyageurs pour le total groupe ;
- `kgCO2e/vehicle-km` : une hypothèse d’occupation documentée est nécessaire avant attribution par voyageur.

Aucune valeur n’est fournie ici. Les tâches futures devront définir et tester les règles d’arrondi, la sélection temporelle/géographique des facteurs et la gestion précise de l’occupation avant d’activer un calcul.

## Couverture et comparaison

- calculer séparément chaque étape couverte ;
- exposer distance totale et distance couverte ;
- utiliser `partial` si seule une partie mesurable est calculée ;
- utiliser `unavailable` si aucun résultat défendable n’est possible ;
- ne jamais remplacer une valeur inconnue par zéro ;
- ne classer deux résultats que si unité, périmètre et méthode sont compatibles via une même `comparisonKey` ;
- ne jamais mélanger silencieusement périmètre opérationnel et cycle de vie.

Un total partiel décrit uniquement les étapes couvertes et n’autorise pas un classement carbone global. La marche, l’attente, les correspondances et les retours doivent rester explicitement modélisés, sans double comptage.

## Limites

Le contrat ne constitue ni analyse de cycle de vie complète, ni conseil de réservation. Il ne couvre pas les émissions hôtelières, la disponibilité, les prix, le forçage radiatif, l’infrastructure, les effets rebond ou un « score écologique » composite. Une preuve d’hébergement porte sur une allégation précise; elle ne permet pas d’inférer une performance globale.

Toute évolution doit être accompagnée de sources datées, des droits de réutilisation, d’hypothèses visibles et de tests de non-comparabilité pour les cas incomplets ou incompatibles.
