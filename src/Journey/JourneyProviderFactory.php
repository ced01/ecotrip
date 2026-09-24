<?php

declare(strict_types=1);

namespace App\Journey;

use App\Demo\DemoJourneyProvider;
use App\Provider\JourneyProvider;

final readonly class JourneyProviderFactory
{
    public function __construct(private DemoJourneyProvider $demo, private FilBleuJourneyProvider $filbleu, private string $provider) {}
    public function create(): JourneyProvider
    {
        return match($this->provider){'demo'=>$this->demo,'filbleu'=>$this->filbleu,default=>throw new \InvalidArgumentException('Unknown ECOTRIP_JOURNEY_PROVIDER; expected demo or filbleu.')};
    }
}
