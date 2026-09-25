<?php

declare(strict_types=1);

namespace App\Environmental;

/**
 * Explicit qualification boundary for publications selectable by the repository.
 *
 * The runtime registry contains only the reviewed ADEME V23.6 publication. The
 * synthetic factory exists solely to exercise versioned-history SQL without
 * claiming that another real ADEME publication has been qualified.
 */
final readonly class AdemePublicationRegistry
{
    /** @var list<array{sourceId: string, sourceVersion: string, checksum: string, effectiveFrom: string}> */
    private array $publications;

    /**
     * @param list<array{sourceId: string, sourceVersion: string, checksum: string, effectiveFrom: string}>|null $publications
     */
    public function __construct(?array $publications = null)
    {
        $this->publications = $publications ?? [[
            'sourceId' => AdemeEmissionFactorImporter::SOURCE_ID,
            'sourceVersion' => AdemeEmissionFactorImporter::SOURCE_VERSION,
            'checksum' => AdemeEmissionFactorImporter::OFFICIAL_SHA256,
            'effectiveFrom' => AdemeEmissionFactorImporter::EFFECTIVE_FROM,
        ]];
        $this->validate();
    }

    public static function officialWithChecksum(string $checksum): self
    {
        return new self([[
            'sourceId' => AdemeEmissionFactorImporter::SOURCE_ID,
            'sourceVersion' => AdemeEmissionFactorImporter::SOURCE_VERSION,
            'checksum' => $checksum,
            'effectiveFrom' => AdemeEmissionFactorImporter::EFFECTIVE_FROM,
        ]]);
    }

    /**
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

        return new self($qualified);
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
    }

    private function isExactDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
