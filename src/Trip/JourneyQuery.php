<?php
namespace App\Trip;
/** Validated criteria for one direction; orchestration searches the return separately. */
final readonly class JourneyQuery
{
    /** @param list<string> $modes Values from OpenAPI Mode. */
    public function __construct(
        public string $originId,
        public string $destinationId,
        public \DateTimeImmutable $date,
        public int $travelers,
        public array $modes,
    ) {}
}
