<?php

declare(strict_types=1);

namespace App\Provider;

interface CapabilitiesProvider
{
    /** @return array<string, mixed> OpenAPI Capabilities */
    public function capabilities(): array;
}
