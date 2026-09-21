<?php

declare(strict_types=1);

namespace App\Accommodation;

final readonly class EnvironmentalEvidence
{
    private function __construct(
        private string $id,
        private string $claim,
        private string $kind,
        private string $status,
        private ?string $organization,
        private ?string $referenceUrl,
        private ?string $validFrom,
        private ?string $validUntil,
        private ?string $checkedAt,
        private string $sourceId,
    ) {
    }

    public static function certification(
        string $id,
        string $claim,
        ?string $organization,
        ?string $referenceUrl,
        ?string $validFrom,
        ?string $validUntil,
        ?string $checkedAt,
        string $sourceId,
    ): self {
        if ($organization === null || $organization === '' || $referenceUrl === null || filter_var($referenceUrl, FILTER_VALIDATE_URL) === false || $checkedAt === null) {
            throw new \InvalidArgumentException('Certification requires organization, reference URL and check date.');
        }
        self::assertDate($checkedAt);
        if ($validFrom !== null) {
            self::assertDate($validFrom);
        }
        if ($validUntil !== null) {
            self::assertDate($validUntil);
        }
        if ($validFrom !== null && $validUntil !== null && $validUntil < $validFrom) {
            throw new \InvalidArgumentException('Certification validity end precedes its start.');
        }

        // This task only ships synthetic data: even complete proof metadata must not be called verified.
        return new self($id, $claim, 'certification', 'demo', $organization, $referenceUrl, $validFrom, $validUntil, $checkedAt, $sourceId);
    }

    public static function declaration(string $id, string $claim, string $sourceId): self
    {
        return new self($id, $claim, 'declaration', 'declared', null, null, null, null, null, $sourceId);
    }

    /** @return array<string, string|null> */
    public function toArray(\DateTimeImmutable $onDate): array
    {
        $status = $this->status;
        if ($this->kind === 'certification' && $this->validUntil !== null && $this->validUntil < $onDate->format('Y-m-d')) {
            $status = 'expired';
        }

        return [
            'id' => $this->id,
            'claim' => $this->claim,
            'kind' => $this->kind,
            'status' => $status,
            'organization' => $this->organization,
            'referenceUrl' => $this->referenceUrl,
            'validFrom' => $this->validFrom,
            'validUntil' => $this->validUntil,
            'checkedAt' => $this->checkedAt,
            'sourceId' => $this->sourceId,
        ];
    }

    private static function assertDate(string $value): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Evidence dates must use YYYY-MM-DD.');
        }
    }
}
