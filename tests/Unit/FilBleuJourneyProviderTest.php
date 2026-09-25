<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Journey\FilBleuJourneyProvider;
use App\Journey\JourneyScheduleRepository;
use App\Provider\PlaceReferenceResolver;
use App\Provider\ProviderUnavailable;
use App\Trip\DirectionStatus;
use App\Trip\JourneyQuery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FilBleuJourneyProviderTest extends TestCase
{
    #[Test]
    public function missingMappingReturnsOutOfCoverageWithoutScheduleQuery(): void
    {
        $resolver = new class implements PlaceReferenceResolver {
            public function toExternalId(string $placeId, string $providerKey): ?string { return $placeId === 'known' ? 'station-a' : null; }
            public function toInternalId(string $providerKey, string $externalId): ?string { throw new \LogicException('not used'); }
        };
        $repository = new class implements JourneyScheduleRepository {
            public int $calls = 0;
            public function direct(string $originStation, string $destinationStation, \DateTimeImmutable $date): array { ++$this->calls; return []; }
            public function source(): array { throw new \LogicException('must not query source'); }
        };
        $provider = new FilBleuJourneyProvider($resolver, $repository);

        $result = $provider->search(new JourneyQuery('known', 'missing', new \DateTimeImmutable('2026-09-23'), 1, ['public_transport']));

        self::assertSame(DirectionStatus::OutOfCoverage, $result->status);
        self::assertSame(0, $repository->calls);
    }

    #[Test]
    public function realRowsExposeOnlyEcoTripIdsAndNoSyntheticCarbon(): void
    {
        $resolver = new class implements PlaceReferenceResolver {
            public function toExternalId(string $placeId, string $providerKey): ?string { return ['eco-a' => 'raw-a', 'eco-b' => 'raw-b'][$placeId] ?? null; }
            public function toInternalId(string $providerKey, string $externalId): ?string { return ['raw-a' => 'eco-a', 'raw-b' => 'eco-b'][$externalId] ?? null; }
        };
        $repository = new class implements JourneyScheduleRepository {
            public function direct(string $originStation, string $destinationStation, \DateTimeImmutable $date): array { return [['trip' => 'raw-trip', 'routeName' => '2', 'departureSequence' => 1, 'arrivalSequence' => 2, 'departureSeconds' => 90000, 'arrivalSeconds' => 90300]]; }
            public function source(): array { return ['id'=>'filbleu-gtfs','publisher'=>'Fil Bleu','url'=>'https://example.test','license'=>'Licence Ouverte 2.0','accessedAt'=>'2026-09-23','version'=>'10048_164382514','reuseNotes'=>'horaire théorique','dataStatus'=>'verified']; }
        };
        $provider = new FilBleuJourneyProvider($resolver, $repository);

        $result = $provider->search(new JourneyQuery('eco-a', 'eco-b', new \DateTimeImmutable('2026-10-25', new \DateTimeZone('Europe/Paris')), 2, ['public_transport']));

        self::assertSame(DirectionStatus::Complete, $result->status);
        $json = json_encode($result->itineraries, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('raw-', $json);
        $itinerary = $result->itineraries[0];
        self::assertSame('real', $itinerary['dataStatus']);
        self::assertSame('2026-10-26T01:00:00+01:00', $itinerary['legs'][0]['schedule']['departureAt']);
        self::assertSame('2026-10-26T01:05:00+01:00', $itinerary['legs'][0]['schedule']['arrivalAt']);
        self::assertSame(5, $itinerary['durationMinutes']);
        self::assertNull($itinerary['legs'][0]['distance']['km']);
        self::assertSame('unknown', $itinerary['legs'][0]['distance']['method']);
        self::assertSame('bus_urban', $itinerary['legs'][0]['subtype']);
        self::assertSame('unavailable', $itinerary['emissions']['status']);
        self::assertNull($itinerary['emissions']['kgCO2ePerTraveler']);
        self::assertFalse($itinerary['emissions']['comparable']);
        self::assertSame('verified', $itinerary['provenance']['status']);
    }

    #[Test]
    public function repositoryFailureNeverFallsBackToDemo(): void
    {
        $resolver = new class implements PlaceReferenceResolver {
            public function toExternalId(string $placeId, string $providerKey): ?string { return 'mapped'; }
            public function toInternalId(string $providerKey, string $externalId): ?string { return null; }
        };
        $repository = new class implements JourneyScheduleRepository {
            public function direct(string $originStation, string $destinationStation, \DateTimeImmutable $date): array { throw new ProviderUnavailable(); }
            public function source(): array { return []; }
        };
        $this->expectException(ProviderUnavailable::class);
        (new FilBleuJourneyProvider($resolver, $repository))->search(new JourneyQuery('a', 'b', new \DateTimeImmutable('2026-09-23'), 1, ['public_transport']));
    }

    #[Test]
    public function inconsistentReverseMappingReturnsPartialWithoutExposingRows(): void
    {
        $resolver = new class implements PlaceReferenceResolver {
            public function toExternalId(string $placeId, string $providerKey): ?string { return ['eco-a' => 'raw-a', 'eco-b' => 'raw-b'][$placeId] ?? null; }
            public function toInternalId(string $providerKey, string $externalId): ?string { return 'different-place'; }
        };
        $repository = new class implements JourneyScheduleRepository {
            public function direct(string $originStation, string $destinationStation, \DateTimeImmutable $date): array { return [['trip' => 'raw-trip', 'routeName' => '2', 'departureSequence' => 1, 'arrivalSequence' => 2, 'departureSeconds' => 100, 'arrivalSeconds' => 200]]; }
            public function source(): array { throw new \LogicException('Source must not be queried for inconsistent mappings.'); }
        };

        $result = (new FilBleuJourneyProvider($resolver, $repository))->search(new JourneyQuery('eco-a', 'eco-b', new \DateTimeImmutable('2026-09-23'), 1, ['public_transport']));

        self::assertSame(DirectionStatus::Partial, $result->status);
        self::assertSame([], $result->itineraries);
        self::assertNotEmpty($result->warnings);
    }

    #[Test]
    #[DataProvider('daylightSavingTransitions')]
    public function gtfsServiceDayUsesNoonMinusTwelveHoursAndElapsedSeconds(
        string $date,
        int $departureSeconds,
        int $arrivalSeconds,
        string $expectedDeparture,
        string $expectedArrival,
    ): void {
        $provider = new FilBleuJourneyProvider($this->mappedResolver(), $this->repositoryWithRows([[
            'trip' => 'dst-trip', 'routeName' => 'A', 'departureSequence' => 10, 'arrivalSequence' => 20,
            'departureSeconds' => $departureSeconds, 'arrivalSeconds' => $arrivalSeconds,
        ]]));

        $result = $provider->search(new JourneyQuery('eco-a', 'eco-b', new \DateTimeImmutable($date, new \DateTimeZone('Europe/Paris')), 1, ['public_transport']));
        $leg = $result->itineraries[0]['legs'][0];

        self::assertSame($expectedDeparture, $leg['schedule']['departureAt']);
        self::assertSame($expectedArrival, $leg['schedule']['arrivalAt']);
        self::assertSame($arrivalSeconds - $departureSeconds, strtotime($expectedArrival) - strtotime($expectedDeparture));
        self::assertSame((int) ceil(($arrivalSeconds - $departureSeconds) / 60), $leg['durationMinutes']);
    }

    public static function daylightSavingTransitions(): iterable
    {
        yield 'spring skips nonexistent 02:30 while preserving elapsed hour' => ['2026-03-29', 9_000, 12_600, '2026-03-29T01:30:00+01:00', '2026-03-29T03:30:00+02:00'];
        yield 'autumn selects second 02:30 and preserves elapsed hour' => ['2026-10-25', 9_000, 12_600, '2026-10-25T02:30:00+01:00', '2026-10-25T03:30:00+01:00'];
        yield 'greater than 24 hours remains elapsed GTFS time' => ['2026-10-25', 90_000, 90_300, '2026-10-26T01:00:00+01:00', '2026-10-26T01:05:00+01:00'];
    }

    #[Test]
    #[DataProvider('resolverFailures')]
    public function resolverStorageFailuresBecomeProviderUnavailable(string $failureMethod): void
    {
        $resolver = new class($failureMethod) implements PlaceReferenceResolver {
            public function __construct(private readonly string $failureMethod) {}
            public function toExternalId(string $placeId, string $providerKey): ?string
            {
                if ($this->failureMethod === __FUNCTION__) throw new \RuntimeException('secret external SQL');
                return ['eco-a' => 'raw-a', 'eco-b' => 'raw-b'][$placeId] ?? null;
            }
            public function toInternalId(string $providerKey, string $externalId): ?string
            {
                if ($this->failureMethod === __FUNCTION__) throw new \RuntimeException('secret internal SQL');
                return ['raw-a' => 'eco-a', 'raw-b' => 'eco-b'][$externalId] ?? null;
            }
        };

        $this->expectException(ProviderUnavailable::class);
        (new FilBleuJourneyProvider($resolver, $this->repositoryWithRows([[
            'trip' => 'trip', 'routeName' => 'A', 'departureSequence' => 1, 'arrivalSequence' => 2,
            'departureSeconds' => 100, 'arrivalSeconds' => 200,
        ]])))->search(new JourneyQuery('eco-a', 'eco-b', new \DateTimeImmutable('2026-09-23'), 1, ['public_transport']));
    }

    public static function resolverFailures(): iterable
    {
        yield 'internal to GTFS' => ['toExternalId'];
        yield 'GTFS to internal' => ['toInternalId'];
    }

    #[Test]
    public function distinctSequencePairsProduceUniqueStablePublicIdentities(): void
    {
        $rows = [
            ['trip'=>'loop','routeName'=>'A','departureSequence'=>1,'arrivalSequence'=>4,'departureSeconds'=>100,'arrivalSeconds'=>400],
            ['trip'=>'loop','routeName'=>'A','departureSequence'=>5,'arrivalSequence'=>8,'departureSeconds'=>500,'arrivalSeconds'=>800],
        ];
        $query = new JourneyQuery('eco-a', 'eco-b', new \DateTimeImmutable('2026-09-23'), 1, ['public_transport']);
        $provider = new FilBleuJourneyProvider($this->mappedResolver(), $this->repositoryWithRows($rows));

        $first = $provider->search($query)->itineraries;
        $second = $provider->search($query)->itineraries;

        self::assertSame($first, $second);
        self::assertCount(2, array_unique(array_column($first, 'id')));
        self::assertNotSame($first[0]['legs'][0]['id'], $first[1]['legs'][0]['id']);
    }

    private function mappedResolver(): PlaceReferenceResolver
    {
        return new class implements PlaceReferenceResolver {
            public function toExternalId(string $placeId, string $providerKey): ?string { return ['eco-a'=>'raw-a','eco-b'=>'raw-b'][$placeId] ?? null; }
            public function toInternalId(string $providerKey, string $externalId): ?string { return ['raw-a'=>'eco-a','raw-b'=>'eco-b'][$externalId] ?? null; }
        };
    }

    /** @param list<array<string, int|string>> $rows */
    private function repositoryWithRows(array $rows): JourneyScheduleRepository
    {
        return new class($rows) implements JourneyScheduleRepository {
            public function __construct(private readonly array $rows) {}
            public function direct(string $originStation, string $destinationStation, \DateTimeImmutable $date): array { return $this->rows; }
            public function source(): array { return ['id'=>'filbleu-gtfs','publisher'=>'Fil Bleu','url'=>'https://example.test','license'=>'Licence Ouverte 2.0','accessedAt'=>'2026-09-23','version'=>'fixture','reuseNotes'=>'test','dataStatus'=>'verified']; }
        };
    }
}
