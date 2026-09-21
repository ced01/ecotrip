<?php

declare(strict_types=1);

namespace App\Provider;

/** Response-level provenance supplied by the configured journey provider. */
final readonly class JourneyProviderMetadata
{
    /**
     * @param list<array<string, mixed>> $sources
     * @param list<string>               $warnings
     */
    public function __construct(
        public string $dataMode,
        public array $sources,
        public array $warnings,
    ) {}
}
