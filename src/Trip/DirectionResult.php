<?php
namespace App\Trip;
/** Wire shapes are defined by docs/openapi.yaml; no ORM object crosses this boundary. */
final readonly class DirectionResult
{
    /** @param list<array<string, mixed>> $itineraries OpenAPI Itinerary[]
     *  @param list<string> $warnings
     */
    public function __construct(
        public DirectionStatus $status,
        public array $itineraries,
        public array $warnings = [],
    ) {}
}
