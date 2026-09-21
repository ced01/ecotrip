<?php
namespace App\Accommodation;
final readonly class AccommodationQuery
{
    public function __construct(
        public string $destinationId,
        public ?bool $bicycleParking = null,
        public ?bool $publicTransportNearby = null,
        public int $limit = 20,
        public int $offset = 0,
        public ?string $bicycleParkingState = null,
        public ?string $publicTransportNearbyState = null,
    ) {}
}
