<?php

declare(strict_types=1);

namespace App\Accommodation;

final readonly class Accommodation
{
    /**
     * @param list<EnvironmentalEvidence> $evidence
     * @param array{amount: int|float, currency: string, basis: string, asOf: string, sourceId: string, dataStatus: string}|null $price
     */
    public function __construct(
        private string $id,
        private string $name,
        private string $destinationId,
        private ?bool $bicycleParking,
        private ?bool $publicTransportNearby,
        private ?int $publicTransportDistanceMeters,
        private ?array $price,
        private array $evidence,
    ) {
        if ($publicTransportDistanceMeters !== null && $publicTransportDistanceMeters < 0) {
            throw new \InvalidArgumentException('Transport distance cannot be negative.');
        }
        if ($publicTransportNearby !== null && $publicTransportDistanceMeters === null) {
            throw new \InvalidArgumentException('Known transport proximity requires a measured distance.');
        }
        if ($publicTransportNearby === null && $publicTransportDistanceMeters !== null) {
            throw new \InvalidArgumentException('Unknown transport proximity cannot claim a measured distance.');
        }
        if ($price !== null && (!preg_match('/^[A-Z]{3}$/D', $price['currency']) || !in_array($price['basis'], ['night_per_room', 'night_per_person'], true))) {
            throw new \InvalidArgumentException('Price requires an ISO currency and contextual basis.');
        }
    }

    public function feature(string $name): ?bool
    {
        return match ($name) {
            'bicycleParking' => $this->bicycleParking,
            'publicTransportNearby' => $this->publicTransportNearby,
            default => throw new \InvalidArgumentException('Unknown accommodation feature.'),
        };
    }

    /** @return array<string, mixed> */
    public function toArray(\DateTimeImmutable $onDate): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'destinationId' => $this->destinationId,
            'dataStatus' => 'demo',
            'features' => [
                'bicycleParking' => $this->bicycleParking,
                'publicTransportNearby' => $this->publicTransportNearby,
            ],
            'publicTransportDistanceMeters' => $this->publicTransportDistanceMeters,
            'price' => $this->price,
            'evidence' => array_map(static fn (EnvironmentalEvidence $evidence): array => $evidence->toArray($onDate), $this->evidence),
            'provenance' => [
                'status' => 'demo',
                'sourceIds' => ['demo-accommodations'],
                'asOf' => null,
                'note' => 'Fixture synthétique hors ligne; aucune offre, disponibilité ou réservation réelle.',
            ],
        ];
    }
}
