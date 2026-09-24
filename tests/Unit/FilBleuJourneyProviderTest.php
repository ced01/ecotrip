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
            public function direct(string $originStation, string $destinationStation, \DateTimeImmutable $date): array { return [['trip' => 'raw-trip', 'routeName' => '2', 'departureSeconds' => 90000, 'arrivalSeconds' => 90300]]; }
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
        self::assertNull($itinerary['legs'][0]['distance']['km']);
        self::assertSame('unknown', $itinerary['legs'][0]['distance']['method']);
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
            public function direct(string $originStation, string $destinationStation, \DateTimeImmutable $date): array { return [['trip' => 'raw-trip', 'routeName' => '2', 'departureSeconds' => 100, 'arrivalSeconds' => 200]]; }
            public function source(): array { throw new \LogicException('Source must not be queried for inconsistent mappings.'); }
        };

        $result = (new FilBleuJourneyProvider($resolver, $repository))->search(new JourneyQuery('eco-a', 'eco-b', new \DateTimeImmutable('2026-09-23'), 1, ['public_transport']));

        self::assertSame(DirectionStatus::Partial, $result->status);
        self::assertSame([], $result->itineraries);
        self::assertNotEmpty($result->warnings);
    }
}
