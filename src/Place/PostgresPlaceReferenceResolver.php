<?php

declare(strict_types=1);

namespace App\Place;

use App\Provider\PlaceReferenceResolver;
use Doctrine\DBAL\Connection;

final readonly class PostgresPlaceReferenceResolver implements PlaceReferenceResolver
{
    public function __construct(private Connection $connection)
    {
    }

    public function toExternalId(string $placeId, string $providerKey): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT external_id FROM place_external_reference WHERE provider_key = :provider AND place_id = :place AND active = TRUE',
            ['provider' => $providerKey, 'place' => $placeId],
        );

        return $value === false ? null : (string) $value;
    }

    public function toInternalId(string $providerKey, string $externalId): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT place_id FROM place_external_reference WHERE provider_key = :provider AND external_id = :external AND active = TRUE AND place_id IS NOT NULL',
            ['provider' => $providerKey, 'external' => $externalId],
        );

        return $value === false ? null : (string) $value;
    }
}
