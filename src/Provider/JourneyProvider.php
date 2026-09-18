<?php
namespace App\Provider;
use App\Trip\JourneyQuery;
use App\Trip\DirectionResult;
interface JourneyProvider
{
    /** No fallback from real to demo. Total provider failure throws ProviderUnavailable. */
    public function search(JourneyQuery $query): DirectionResult;
}
