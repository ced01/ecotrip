<?php

declare(strict_types=1);

namespace App\Provider;

use App\Trip\DirectionResult;
use App\Trip\JourneyQuery;

interface JourneyProvider
{
    /** No fallback from real to demo. Total provider failure throws ProviderUnavailable. */
    public function search(JourneyQuery $query): DirectionResult;

    /** Metadata must describe this provider, never an implicit demo fallback. */
    public function metadata(): JourneyProviderMetadata;
}
