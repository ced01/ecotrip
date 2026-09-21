<?php

declare(strict_types=1);

namespace App\Environmental;

/**
 * Deterministic, per-leg CO2e estimator. Inputs and output deliberately mirror
 * the OpenAPI transport structures while the calculation remains HTTP-free.
 */
final class EmissionEstimator
{
    public const METHODOLOGY_VERSION = 'carbon-estimation-v1';

    public function __construct(private readonly EmissionFactorRepository $factors)
    {
    }

    /**
     * @param list<array{id:string, mode:string, subtype:?string, distanceKm:int|float|null}> $legs
     * @return array<string, mixed> OpenAPI EmissionEstimate
     */
    public function estimate(array $legs, int $travelers, string $geography, \DateTimeImmutable $date, string $dataStatus): array
    {
        if ($travelers < 1) {
            throw new \InvalidArgumentException('Le nombre de voyageurs doit être positif.');
        }
        if ($legs === []) {
            throw new \InvalidArgumentException('Une estimation exige au moins une étape.');
        }
        if (!in_array($dataStatus, ['demo', 'real'], true)) {
            throw new \InvalidArgumentException('Statut de données invalide.');
        }

        $legEstimates = [];
        $usedFactors = [];
        $assumptions = [];
        $coveredDistance = 0.0;
        $knownDistance = 0.0;
        $allDistancesKnown = true;
        $perTraveler = 0.0;

        foreach ($legs as $leg) {
            $this->validateLeg($leg);
            $distance = $leg['distanceKm'] === null ? null : (float) $leg['distanceKm'];
            if ($distance === null) {
                $allDistancesKnown = false;
                $legEstimates[] = $this->unavailableLeg($leg['id'], 'Distance inconnue.');
                continue;
            }
            $knownDistance += $distance;

            $candidates = array_values(array_filter(
                $this->factors->findCandidates($leg['mode'], $leg['subtype'], $geography, $date),
                fn (array $factor): bool => $this->isApplicable($factor, $leg, $geography, $date),
            ));
            if (count($candidates) !== 1) {
                $legEstimates[] = $this->unavailableLeg(
                    $leg['id'],
                    $candidates === [] ? 'Aucun facteur applicable.' : 'Plusieurs facteurs applicables sans priorité explicite.',
                );
                continue;
            }

            $factor = $candidates[0];
            $this->validateFactor($factor);
            if ($factor['unit'] === 'kgCO2e/vehicle-km' && $factor['occupancy'] === null) {
                $legEstimates[] = $this->unavailableLeg($leg['id'], 'Occupation requise pour un facteur véhicule-km.');
                continue;
            }

            $legPerTraveler = $distance * (float) $factor['value'];
            if ($factor['unit'] === 'kgCO2e/vehicle-km') {
                $legPerTraveler /= (float) $factor['occupancy'];
                $assumptions[] = sprintf(
                    'Occupation moyenne du facteur %s : %s voyageurs par véhicule.',
                    $factor['id'],
                    $this->formatNumber((float) $factor['occupancy']),
                );
            }
            $legGroup = $legPerTraveler * $travelers;
            $coveredDistance += $distance;
            $perTraveler += $legPerTraveler;
            $usedFactors[$factor['id']] = $factor;
            $synthetic = $factor['status'] === 'synthetic_test';
            $demo = $dataStatus === 'demo' || $synthetic;
            $legEstimates[] = [
                'legId' => $leg['id'],
                'status' => $demo ? 'demo' : 'complete',
                'kgCO2ePerTraveler' => $this->round($legPerTraveler),
                'kgCO2eGroup' => $this->round($legGroup),
                'factorId' => $factor['id'],
                'reason' => $dataStatus === 'demo'
                    ? 'Calcul de démonstration (provenance des données du trajet).'
                    : ($synthetic ? 'Calcul de démonstration avec facteur synthétique.' : null),
            ];
        }

        $covered = count($usedFactors) > 0 || array_any($legEstimates, static fn (array $leg): bool => $leg['kgCO2ePerTraveler'] !== null);
        $allCovered = array_all($legEstimates, static fn (array $leg): bool => $leg['status'] !== 'unavailable');
        $demo = $dataStatus === 'demo'
            || array_any($usedFactors, static fn (array $factor): bool => $factor['status'] === 'synthetic_test');
        $status = !$covered ? 'unavailable' : ($demo ? 'demo' : (!$allCovered ? 'partial' : 'complete'));
        $comparable = $allCovered && $this->hasHomogeneousMethod($usedFactors);
        $comparisonKey = $comparable ? $this->comparisonKey($usedFactors, $dataStatus) : null;

        return [
            'status' => $status,
            'kgCO2ePerTraveler' => $covered ? $this->round($perTraveler) : null,
            'kgCO2eGroup' => $covered ? $this->round($perTraveler * $travelers) : null,
            'coveredDistanceKm' => $this->round($coveredDistance),
            'totalDistanceKm' => $allDistancesKnown ? $this->round($knownDistance) : null,
            'comparable' => $comparable,
            'comparisonKey' => $comparisonKey,
            'methodologyVersion' => self::METHODOLOGY_VERSION,
            'assumptions' => array_values(array_unique($assumptions)),
            'legs' => $legEstimates,
            'factors' => array_values($usedFactors),
        ];
    }

    /** @param array<string, mixed> $leg */
    private function validateLeg(array $leg): void
    {
        foreach (['id', 'mode', 'subtype', 'distanceKm'] as $key) {
            if (!array_key_exists($key, $leg)) {
                throw new \InvalidArgumentException("Champ d'étape manquant : {$key}.");
            }
        }
        if (!is_string($leg['id']) || $leg['id'] === '' || !is_string($leg['mode']) || $leg['mode'] === '') {
            throw new \InvalidArgumentException("Identifiant et mode d'étape requis.");
        }
        if ($leg['subtype'] !== null && !is_string($leg['subtype'])) {
            throw new \InvalidArgumentException("Sous-type d'étape invalide.");
        }
        if ($leg['distanceKm'] !== null && (!is_int($leg['distanceKm']) && !is_float($leg['distanceKm']) || $leg['distanceKm'] < 0 || !is_finite((float) $leg['distanceKm']))) {
            throw new \InvalidArgumentException("Distance d'étape invalide.");
        }
    }

    /** @param array<string, mixed> $factor @param array<string, mixed> $leg */
    private function isApplicable(array $factor, array $leg, string $geography, \DateTimeImmutable $date): bool
    {
        if (($factor['mode'] ?? null) !== $leg['mode'] || ($factor['subtype'] ?? null) !== $leg['subtype'] || ($factor['geography'] ?? null) !== $geography) {
            return false;
        }
        try {
            $from = new \DateTimeImmutable((string) ($factor['validFrom'] ?? 'invalid'));
            $until = isset($factor['validUntil']) ? new \DateTimeImmutable((string) $factor['validUntil']) : null;
        } catch (\Exception) {
            return false;
        }
        return $date >= $from && ($until === null || $date <= $until);
    }

    /** @param array<string, mixed> $factor */
    private function validateFactor(array $factor): void
    {
        $required = ['id', 'value', 'unit', 'mode', 'subtype', 'geography', 'validFrom', 'validUntil', 'scope', 'occupancy', 'version', 'sourceId', 'status'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $factor)) {
                throw new \UnexpectedValueException("Facteur incomplet : {$key}.");
            }
        }
        if (!is_string($factor['id']) || $factor['id'] === '' || !is_numeric($factor['value']) || (float) $factor['value'] < 0 || !is_finite((float) $factor['value'])) {
            throw new \UnexpectedValueException('Valeur de facteur invalide.');
        }
        if (!in_array($factor['unit'], ['kgCO2e/passenger-km', 'kgCO2e/vehicle-km'], true)
            || !in_array($factor['scope'], ['operation', 'life_cycle'], true)
            || !in_array($factor['status'], ['verified', 'synthetic_test'], true)) {
            throw new \UnexpectedValueException('Métadonnées de facteur invalides.');
        }
        if (!is_string($factor['version']) || $factor['version'] === '' || !is_string($factor['sourceId']) || $factor['sourceId'] === '') {
            throw new \UnexpectedValueException('Version et source du facteur requises.');
        }
        if ($factor['occupancy'] !== null && (!is_numeric($factor['occupancy']) || (float) $factor['occupancy'] <= 0)) {
            throw new \UnexpectedValueException("Hypothèse d'occupation invalide.");
        }
    }

    /** @return array<string, mixed> */
    private function unavailableLeg(string $id, string $reason): array
    {
        return ['legId' => $id, 'status' => 'unavailable', 'kgCO2ePerTraveler' => null, 'kgCO2eGroup' => null, 'factorId' => null, 'reason' => $reason];
    }

    /** @param array<string, array<string, mixed>> $factors */
    private function hasHomogeneousMethod(array $factors): bool
    {
        $keys = [];
        foreach ($factors as $factor) {
            $keys[] = implode('|', [$factor['unit'], $factor['scope'], $factor['status']]);
        }
        return count(array_unique($keys)) === 1;
    }

    /** @param array<string, array<string, mixed>> $factors */
    private function comparisonKey(array $factors, string $dataStatus): string
    {
        $factor = reset($factors);
        return implode('|', [self::METHODOLOGY_VERSION, 'kgCO2e', $factor['unit'], $factor['scope'], $dataStatus, $factor['status']]);
    }

    private function round(float $value): float
    {
        return round($value, 12);
    }

    private function formatNumber(float $value): string
    {
        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
    }
}
