<?php

declare(strict_types=1);

namespace App\Demo;

use App\Environmental\EmissionEstimator;
use App\Provider\JourneyProvider;
use App\Provider\JourneyProviderMetadata;
use App\Trip\DirectionResult;
use App\Trip\DirectionStatus;
use App\Trip\JourneyQuery;

final class DemoJourneyProvider implements JourneyProvider
{
    public function __construct(private readonly EmissionEstimator $estimator) {}

    public function metadata(): JourneyProviderMetadata
    {
        return new JourneyProviderMetadata(
            'demo',
            [DemoData::source()],
            ['Résultats de démonstration hors ligne; aucune offre réelle ni fallback de fournisseur.'],
        );
    }

    public function search(JourneyQuery $query): DirectionResult
    {
        $covered = ($query->originId === 'demo-paris' && $query->destinationId === 'demo-lyon')
            || ($query->originId === 'demo-lyon' && $query->destinationId === 'demo-paris');
        if (!$covered) {
            return new DirectionResult(DirectionStatus::OutOfCoverage, [], ['Paire absente des scénarios de démonstration.']);
        }

        $direction = $query->originId === 'demo-paris' ? 'outbound' : 'inbound';
        $itineraries = [];
        foreach (['train', 'coach'] as $mode) {
            if (!in_array($mode, $query->modes, true)) {
                continue;
            }
            $itineraries[] = $mode === 'train' ? $this->train($query, $direction) : $this->coach($query, $direction);
        }
        return new DirectionResult($itineraries === [] ? DirectionStatus::Empty : DirectionStatus::Complete, $itineraries, $itineraries === [] ? ['Aucun scénario ne correspond aux modes demandés.'] : []);
    }

    /** @return array<string, mixed> */
    private function train(JourneyQuery $query, string $direction): array
    {
        $middle = 'demo-macon';
        $legs = [
            $this->leg($direction.'-train-1', 'train', $query->originId, $middle, 95, 10, 250.0),
            $this->leg($direction.'-train-2', 'train', $middle, $query->destinationId, 70, 25, 155.0),
        ];
        return $this->itinerary($direction.'-train-via-macon', $direction, $query, $legs, 1);
    }

    /** @return array<string, mixed> */
    private function coach(JourneyQuery $query, string $direction): array
    {
        $legs = [$this->leg($direction.'-coach-1', 'coach', $query->originId, $query->destinationId, 360, 15, 465.0)];
        return $this->itinerary($direction.'-coach-direct', $direction, $query, $legs, 0);
    }

    /** @return array<string, mixed> */
    private function leg(string $id, string $mode, string $origin, string $destination, int $duration, int $waiting, float $distance): array
    {
        $provenance = DemoData::provenance();
        return [
            'id' => $id, 'mode' => $mode, 'subtype' => null, 'originId' => $origin, 'destinationId' => $destination,
            'durationMinutes' => $duration, 'waitingMinutes' => $waiting,
            'distance' => ['km' => $distance, 'method' => 'scenario', 'provenance' => $provenance],
            'schedule' => ['departureAt' => null, 'arrivalAt' => null, 'provenance' => $provenance],
            'provenance' => $provenance,
        ];
    }

    /** @param list<array<string, mixed>> $legs @return array<string, mixed> */
    private function itinerary(string $id, string $direction, JourneyQuery $query, array $legs, int $transfers): array
    {
        $estimateLegs = array_map(static fn (array $leg): array => ['id' => $leg['id'], 'mode' => $leg['mode'], 'subtype' => $leg['subtype'], 'distanceKm' => $leg['distance']['km']], $legs);
        return [
            'id' => $id, 'direction' => $direction, 'requestedDate' => $query->date->format('Y-m-d'), 'dataStatus' => 'demo',
            'legs' => $legs,
            'durationMinutes' => array_sum(array_column($legs, 'durationMinutes')) + array_sum(array_column($legs, 'waitingMinutes')),
            'transfers' => $transfers,
            'emissions' => $this->estimator->estimate($estimateLegs, $query->travelers, 'FR', $query->date, 'demo'),
            'provenance' => DemoData::provenance(),
            'warnings' => ['Durées, attentes, distances et facteurs entièrement synthétiques; aucun horaire réel.'],
        ];
    }
}
