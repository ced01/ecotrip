# Méthodologie environnementale

## Statut et version

La version `carbon-estimation-v1` implémente le calcul par étape et l’endpoint `GET /api/v1/methodology`. Son statut public est `demo` : aucun facteur environnemental réel n’est livré. Les nombres employés par les tests sont fictifs, portent le statut `synthetic_test` et référencent la source `synthetic-tests`; ils démontrent uniquement l’arithmétique et le contrat.

## Indicateur et calcul

L’indicateur est le kilogramme de CO₂ équivalent (`kgCO2e`). Chaque étape conserve le facteur exact utilisé : valeur, unité, mode/sous-type, géographie, période de validité, périmètre, occupation, version, source et statut.

Pour une distance `d`, un facteur `f` et `n` voyageurs :

- `kgCO2e/passenger-km` : individuel = `d × f`; groupe = `d × f × n` ;
- `kgCO2e/vehicle-km` avec occupation explicite `o` : individuel = `d × f ÷ o`; groupe = `d × f ÷ o × n`.

L’occupation est donc une hypothèse d’allocation moyenne, visible dans `assumptions`; elle ne représente ni le remplissage observé ni le nombre de véhicules réservé par le groupe. Un facteur véhicule-km sans occupation est indisponible. Les calculs internes conservent les montants intermédiaires non arrondis : le total individuel est leur somme et le total groupe est cette somme non arrondie multipliée par le nombre de voyageurs. Chaque montant exposé (étape ou total) est ensuite arrondi indépendamment à 12 décimales, une seule fois et au plus près selon la règle PHP `round` (`PHP_ROUND_HALF_UP` par défaut). Un total peut donc conserver des fractions cumulées invisibles dans l’affichage arrondi des étapes; il n’est jamais obtenu en additionnant ces affichages.

Le dépôt fournit les candidats correspondant au mode, sous-type, territoire et jour. Une étape n’est calculée que si exactement un facteur applicable reste sélectionné. Zéro ou plusieurs candidats donnent une raison explicite et évitent un choix implicite.

## Statuts, couverture et valeurs inconnues

- `complete` : toutes les étapes de provenance réelle sont calculées avec des facteurs vérifiés ;
- `demo` : au moins une émission calculée provient d’un trajet `demo` ou d’un facteur synthétique, y compris lorsque la couverture est incomplète ;
- `partial` : certaines étapes seulement sont calculées, sans aucune provenance de démonstration ;
- `unavailable` : aucune étape ne peut être calculée.

Chaque étape calculée vaut `demo` dès que le trajet est de démonstration ou que son facteur est synthétique; sa `reason` indique laquelle de ces provenances impose ce statut. Elle vaut sinon `complete`; une étape non calculable reste `unavailable` avec sa raison. Sur un trajet `demo` partiellement couvert, le statut global reste donc `demo`, tandis que les étapes indisponibles et `comparable: false` rendent la couverture partielle explicite sans masquer la provenance. Une somme partielle porte uniquement sur les étapes couvertes. `coveredDistanceKm` est toujours explicite; `totalDistanceKm` vaut `null` dès qu’une distance est inconnue. Une émission inconnue vaut `null`, jamais zéro. Une distance réellement nulle avec un facteur applicable produit en revanche une émission numérique nulle.

## Comparabilité

Une estimation est comparable uniquement si elle est complète et si tous ses facteurs partagent unité, périmètre (`operation` ou `life_cycle`) et statut de facteur. Sa `comparisonKey` encode la version de méthodologie, l’indicateur, l’unité, le périmètre, la provenance du trajet (`real` ou `demo`) et le statut des facteurs. Deux estimations homogènes de démonstration peuvent donc être comparées entre elles, mais une estimation réelle et une estimation de démonstration n’ont jamais la même clé, même si elles emploient le même facteur vérifié. Le comparateur refuse explicitement :

- les estimations partielles ou indisponibles ;
- les unités différentes ;
- les périmètres opération et cycle de vie différents ;
- les méthodes ou statuts réel/démonstration différents.

Un statut `demo` reste visible même lorsqu’une comparaison arithmétique entre deux scénarios synthétiques homogènes est possible. Il ne constitue jamais une affirmation environnementale réelle.

## Limites

La méthode ne constitue ni analyse de cycle de vie complète, ni conseil de réservation. Elle ne couvre pas les émissions hôtelières, la disponibilité, les prix, le forçage radiatif, l’infrastructure, les effets rebond ou un « score écologique » composite. La qualité d’un résultat dépend de la distance et du facteur fournis; aucune valeur manquante n’est imputée et aucun fallback de données réelles vers une fixture n’est autorisé.

Toute intégration future de facteurs réels devra documenter l’éditeur exact, l’URL, la licence, la version, la date d’accès, la validité, la géographie, le périmètre et les limites avant d’utiliser le statut `verified`.
