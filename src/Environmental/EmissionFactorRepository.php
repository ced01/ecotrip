<?php
namespace App\Environmental;
interface EmissionFactorRepository
{
    /** @return list<array<string, mixed>> OpenAPI Factor[] candidates; the estimator requires exactly one applicable candidate and never substitutes zero. */
    public function findCandidates(string $mode, ?string $subtype, string $geography, \DateTimeImmutable $date): array;
}
