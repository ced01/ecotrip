<?php

declare(strict_types=1);

namespace App\Place;

use App\Provider\PlaceProvider;

final readonly class PlaceProviderFactory
{
    public function __construct(
        private \App\Demo\DemoPlaceCatalog $demo,
        private PostgresPlaceCatalog $filbleu,
        private string $provider,
    ) {
    }

    public function create(): PlaceProvider
    {
        return match ($this->provider) {
            'demo' => $this->demo,
            'filbleu' => $this->filbleu,
            default => throw new \InvalidArgumentException('Unknown ECOTRIP_PLACE_PROVIDER; expected demo or filbleu.'),
        };
    }
}
