<?php

declare(strict_types=1);

namespace App\Provider;

interface PlaceReferenceResolver
{
    public function toExternalId(string $placeId, string $providerKey): ?string;

    public function toInternalId(string $providerKey, string $externalId): ?string;
}
