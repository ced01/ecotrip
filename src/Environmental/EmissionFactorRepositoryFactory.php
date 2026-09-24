<?php

declare(strict_types=1);

namespace App\Environmental;

use App\Demo\DemoEmissionFactorRepository;

final readonly class EmissionFactorRepositoryFactory
{
    public function __construct(private DemoEmissionFactorRepository $demo, private PostgresEmissionFactorRepository $ademe, private string $provider) {}

    public function create(): EmissionFactorRepository
    {
        if ($this->provider === 'demo') return $this->demo;
        if ($this->provider !== 'ademe') throw new \InvalidArgumentException('Unknown ECOTRIP_EMISSION_FACTOR_PROVIDER; expected demo or ademe.');
        if (!$this->ademe->isInitialized()) throw new \RuntimeException('ADEME emission-factor provider requested without a complete import; no demo fallback is allowed.');
        return $this->ademe;
    }
}
