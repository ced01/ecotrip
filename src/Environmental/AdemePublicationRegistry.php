<?php

declare(strict_types=1);

namespace App\Environmental;

/** Immutable qualification boundary between official runtime data and test-only structures. */
final readonly class AdemePublicationRegistry
{
    /** @var list<array{sourceId: string, sourceVersion: string, checksum: string, effectiveFrom: string}> */
    private array $publications;

    /**
     * Deliberately private: only official() can create a production-eligible registry.
     *
     * @param list<array{sourceId: string, sourceVersion: string, checksum: string, effectiveFrom: string}> $publications
     */
    private function __construct(array $publications, private bool $productionEligible)
    {
        $this->publications = $publications;
        $this->validate();
    }

    /** The sole production registry; every value is pinned in reviewed source code. */
    public static function official(): self
    {
        return new self([[
            'sourceId' => AdemeEmissionFactorImporter::SOURCE_ID,
            'sourceVersion' => AdemeEmissionFactorImporter::SOURCE_VERSION,
            'checksum' => AdemeEmissionFactorImporter::OFFICIAL_SHA256,
            'effectiveFrom' => AdemeEmissionFactorImporter::EFFECTIVE_FROM,
        ]], true);
    }

    /** Test-only seam for a derived fixture using the otherwise exact V23.6 structure. */
    public static function arbitraryChecksumForRepositoryTest(string $checksum): self
    {
        return new self([[
            'sourceId' => AdemeEmissionFactorImporter::SOURCE_ID,
            'sourceVersion' => AdemeEmissionFactorImporter::SOURCE_VERSION,
            'checksum' => $checksum,
            'effectiveFrom' => AdemeEmissionFactorImporter::EFFECTIVE_FROM,
        ]], false);
    }

    /**
     * Test-only seam for versioned-history SQL. These publications can never activate the runtime provider.
     *
     * @param list<array{sourceVersion: string, checksum: string, effectiveFrom: string}> $publications
     */
    public static function syntheticForRepositoryTest(array $publications): self
    {
        $qualified = [];
        foreach ($publications as $publication) {
            if (!str_starts_with($publication['sourceVersion'] ?? '', 'SYNTHETIC-')) {
                throw new \InvalidArgumentException('Synthetic repository publications must be visibly prefixed SYNTHETIC-.');
            }
            $qualified[] = [
                'sourceId' => AdemeEmissionFactorImporter::SOURCE_ID,
                'sourceVersion' => $publication['sourceVersion'],
                'checksum' => $publication['checksum'],
                'effectiveFrom' => $publication['effectiveFrom'],
            ];
        }

        return new self($qualified, false);
    }

    public function isProductionEligible(): bool
    {
        return $this->productionEligible;
    }

    /** @return list<array{sourceId: string, sourceVersion: string, checksum: string, effectiveFrom: string}> */
    public function publications(): array
    {
        return $this->publications;
    }

    private function validate(): void
    {
        if ($this->publications === []) {
            throw new \InvalidArgumentException('At least one qualified ADEME publication is required.');
        }
        $versions = [];
        $effectiveDates = [];
        foreach ($this->publications as $publication) {
            if (($publication['sourceId'] ?? '') !== AdemeEmissionFactorImporter::SOURCE_ID
                || ($publication['sourceVersion'] ?? '') === ''
                || !preg_match('/^[0-9a-f]{64}$/D', $publication['checksum'] ?? '')
                || !$this->isExactDate($publication['effectiveFrom'] ?? '')) {
                throw new \InvalidArgumentException('Invalid qualified ADEME publication registry entry.');
            }
            if (isset($versions[$publication['sourceVersion']]) || isset($effectiveDates[$publication['effectiveFrom']])) {
                throw new \InvalidArgumentException('Qualified ADEME publication versions and effective dates must be unique.');
            }
            $versions[$publication['sourceVersion']] = true;
            $effectiveDates[$publication['effectiveFrom']] = true;
        }

        if ($this->productionEligible && $this->publications !== self::officialValues()) {
            throw new \LogicException('Only the pinned official ADEME publication can be production-eligible.');
        }
    }

    /** @return list<array{sourceId: string, sourceVersion: string, checksum: string, effectiveFrom: string}> */
    private static function officialValues(): array
    {
        return [[
            'sourceId' => AdemeEmissionFactorImporter::SOURCE_ID,
            'sourceVersion' => AdemeEmissionFactorImporter::SOURCE_VERSION,
            'checksum' => AdemeEmissionFactorImporter::OFFICIAL_SHA256,
            'effectiveFrom' => AdemeEmissionFactorImporter::EFFECTIVE_FROM,
        ]];
    }

    private function isExactDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
