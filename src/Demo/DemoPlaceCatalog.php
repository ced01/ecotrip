<?php

declare(strict_types=1);

namespace App\Demo;

use App\Provider\PlaceProvider;

final class DemoPlaceCatalog implements PlaceProvider
{
    /** @var list<array<string, mixed>> */
    private array $places;

    public function __construct()
    {
        $provenance = DemoData::provenance();
        $this->places = [
            ['id' => 'demo-paris', 'name' => 'Paris (scénario)', 'countryCode' => 'FR', 'latitude' => null, 'longitude' => null, 'timezone' => 'Europe/Paris', 'provenance' => $provenance],
            ['id' => 'demo-lyon', 'name' => 'Lyon (scénario)', 'countryCode' => 'FR', 'latitude' => null, 'longitude' => null, 'timezone' => 'Europe/Paris', 'provenance' => $provenance],
            ['id' => 'demo-macon', 'name' => 'Mâcon (correspondance scénario)', 'countryCode' => 'FR', 'latitude' => null, 'longitude' => null, 'timezone' => 'Europe/Paris', 'provenance' => $provenance],
        ];
    }

    public function search(string $query, int $limit = 20, int $offset = 0): array
    {
        $needle = mb_strtolower(trim($query));
        $matches = array_values(array_filter($this->places, static fn (array $place): bool => $needle === '' || str_contains(mb_strtolower($place['name'].' '.$place['id']), $needle)));
        return ['items' => array_slice($matches, $offset, $limit), 'page' => ['limit' => $limit, 'offset' => $offset, 'total' => count($matches)], 'sources' => [DemoData::source()]];
    }

    public function has(string $id): bool
    {
        return array_any($this->places, static fn (array $place): bool => $place['id'] === $id);
    }

    /** @return array<string, mixed> */
    public function capabilities(): array
    {
        return [
            'mode' => 'demo', 'countries' => ['FR'], 'modes' => ['train', 'coach'],
            'coveredPairs' => [
                ['originId' => 'demo-paris', 'destinationId' => 'demo-lyon'],
                ['originId' => 'demo-lyon', 'destinationId' => 'demo-paris'],
            ],
            'limits' => ['maxTravelers' => 9, 'maxResultsPerDirection' => 20, 'maxBodyBytes' => 16384, 'bookingAvailable' => false],
            'warnings' => ['Catalogue et trajets entièrement synthétiques; aucune bascule vers un fournisseur réel.'],
        ];
    }
}
