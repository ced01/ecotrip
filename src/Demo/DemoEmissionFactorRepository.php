<?php

declare(strict_types=1);

namespace App\Demo;

use App\Environmental\EmissionFactorRepository;

final class DemoEmissionFactorRepository implements EmissionFactorRepository
{
    public function findCandidates(string $mode, ?string $subtype, string $geography, \DateTimeImmutable $date): array
    {
        $values = ['train' => 0.02, 'coach' => 0.04];
        if (!isset($values[$mode]) || $subtype !== null || $geography !== 'FR') {
            return [];
        }
        return [[
            'id' => 'demo-'.$mode.'-factor-v1', 'value' => $values[$mode], 'unit' => 'kgCO2e/passenger-km',
            'mode' => $mode, 'subtype' => null, 'geography' => 'FR', 'validFrom' => '2026-01-01', 'validUntil' => null,
            'scope' => 'life_cycle', 'occupancy' => null, 'version' => 'journey-demo-v1', 'sourceId' => DemoData::SOURCE_ID,
            'status' => 'synthetic_test',
        ]];
    }
}
