<?php

declare(strict_types=1);

namespace App\Environmental;

final class EmissionComparator
{
    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    public function compare(array $left, array $right): int
    {
        if (($left['comparable'] ?? false) !== true
            || ($right['comparable'] ?? false) !== true
            || !is_string($left['comparisonKey'] ?? null)
            || $left['comparisonKey'] !== ($right['comparisonKey'] ?? null)
            || !is_numeric($left['kgCO2ePerTraveler'] ?? null)
            || !is_numeric($right['kgCO2ePerTraveler'] ?? null)) {
            throw new \DomainException('Estimations non comparables : couverture, unité, périmètre ou méthode incompatibles.');
        }

        return (float) $left['kgCO2ePerTraveler'] <=> (float) $right['kgCO2ePerTraveler'];
    }
}
