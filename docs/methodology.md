# Méthodologie environnementale

## Statut et version

La version `carbon-estimation-v1` implémente le calcul par étape et l’endpoint `GET /api/v1/methodology`. Son statut public est `demo` : aucun facteur environnemental réel n’est livré. Les nombres employés par les tests sont fictifs, portent le statut `synthetic_test` et référencent la source `synthetic-tests`; ils démontrent uniquement l’arithmétique et le contrat.

## Indicateur et calcul

L’indicateur est le kilogramme de CO₂ équivalent (`kgCO2e`). Chaque étape conserve le facteur exact utilisé : valeur, unité, mode/sous-type, géographie, période de validité, périmètre, occupation, version, source et statut.

Pour une distance `d`, un facteur `f` et `n` voyageurs :

- `kgCO2e/passenger-km` : individuel = `d × f`; groupe = `d × f × n` ;
- `kgCO2e/vehicle-km` avec occupation explicite `o` : individuel = `d × f ÷ o`; groupe = `d × f ÷ o × n`.

L’occupation est donc une hypothèse d’allocation moyenne, visible dans `assumptions`; elle ne représente ni le remplissage observé ni le nombre de véhicules réservé par le groupe. Un facteur véhicule-km sans occupation est indisponible. Les résultats sont arrondis à 12 décimales après chaque étape et sur le total afin de limiter les artefacts binaires tout en conservant la précision des données d’entrée.

Le dépôt fournit les candidats correspondant au mode, sous-type, territoire et jour. Une étape n’est calculée que si exactement un facteur applicable reste sélectionné. Zéro ou plusieurs candidats donnent une raison explicite et évitent un choix implicite.

## Statuts, couverture et valeurs inconnues

- `complete` : toutes les étapes sont calculées avec des facteurs vérifiés ;
- `demo` : toutes les étapes sont calculées et au moins un facteur est synthétique ;
- `partial` : certaines étapes seulement sont calculées ;
- `unavailable` : aucune étape ne peut être calculée.

Chaque étape vaut elle-même `complete`, `demo` ou `unavailable`. Une somme partielle porte uniquement sur les étapes couvertes. `coveredDistanceKm` est toujours explicite; `totalDistanceKm` vaut `null` dès qu’une distance est inconnue. Une émission inconnue vaut `null`, jamais zéro. Une distance réellement nulle avec un facteur applicable produit en revanche une émission numérique nulle.

## Comparabilité

Une estimation est comparable uniquement si elle est complète et si tous ses facteurs partagent unité, périmètre (`operation` ou `life_cycle`) et statut de données. Sa `comparisonKey` encode la version de méthodologie, l’indicateur, l’unité, le périmètre et le statut. Le comparateur refuse explicitement :

- les estimations partielles ou indisponibles ;
- les unités différentes ;
- les périmètres opération et cycle de vie différents ;
- les méthodes ou statuts réel/démonstration différents.

Un statut `demo` reste visible même lorsqu’une comparaison arithmétique entre deux scénarios synthétiques homogènes est possible. Il ne constitue jamais une affirmation environnementale réelle.

## Limites

La méthode ne constitue ni analyse de cycle de vie complète, ni conseil de réservation. Elle ne couvre pas les émissions hôtelières, la disponibilité, les prix, le forçage radiatif, l’infrastructure, les effets rebond ou un « score écologique » composite. La qualité d’un résultat dépend de la distance et du facteur fournis; aucune valeur manquante n’est imputée et aucun fallback de données réelles vers une fixture n’est autorisé.

Toute intégration future de facteurs réels devra documenter l’éditeur exact, l’URL, la licence, la version, la date d’accès, la validité, la géographie, le périmètre et les limites avant d’utiliser le statut `verified`.
