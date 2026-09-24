<?php

declare(strict_types=1);

namespace App\Journey;

interface JourneyScheduleRepository
{
    /** @return list<array{trip:string,routeName:string,departureSequence:int,arrivalSequence:int,departureSeconds:int,arrivalSeconds:int}> */
    public function direct(string $originStation, string $destinationStation, \DateTimeImmutable $date): array;

    /** @return array<string,mixed> */
    public function source(): array;
}
