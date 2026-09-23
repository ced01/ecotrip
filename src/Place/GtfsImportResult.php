<?php

declare(strict_types=1);

namespace App\Place;

final readonly class GtfsImportResult
{
    public function __construct(
        public int $stationCount,
        public int $referenceCount,
        public int $importId,
        public string $feedVersion,
        public string $checksum,
    ) {
    }
}
