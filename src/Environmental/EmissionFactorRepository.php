<?php
namespace App\Environmental;
interface EmissionFactorRepository
{
    /** @return list<array<string, mixed>> OpenAPI Factor[]. No automatic selection or mixed scopes; empty means unavailable, never zero. */
    public function findCandidates(string $mode, ?string $subtype, string $geography, \DateTimeImmutable $date): array;
}
