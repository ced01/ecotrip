<?php

declare(strict_types=1);

namespace App\Environmental;

final readonly class EmissionFactorImportResult
{
    public function __construct(
        public int $importId,
        public string $sourceVersion,
        public string $checksum,
        public int $factorCount,
        public bool $alreadyImported = false,
    ) {}
}
