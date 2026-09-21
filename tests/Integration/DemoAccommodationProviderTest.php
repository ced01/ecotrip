<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Accommodation\Accommodation;
use App\Accommodation\AccommodationQuery;
use App\Accommodation\EnvironmentalEvidence;
use App\Provider\DemoAccommodationProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DemoAccommodationProviderTest extends TestCase
{
    public function testDemoAdapterKeepsUnknownValuesDistinctFromFalseAndMissingPriceFromZero(): void
    {
        $result = (new DemoAccommodationProvider())->search(new AccommodationQuery('demo-lyon'));

        self::assertSame('complete', $result['status']);
        self::assertSame(4, $result['page']['total']);
        self::assertSame('demo', $result['items'][0]['dataStatus']);
        self::assertNull($result['items'][0]['features']['publicTransportNearby']);
        self::assertNull($result['items'][0]['publicTransportDistanceMeters']);
        self::assertNull($result['items'][0]['price']);
        self::assertFalse($result['items'][2]['features']['publicTransportNearby']);
        self::assertSame(0, $result['items'][3]['price']['amount']);
        self::assertSame('EUR', $result['items'][3]['price']['currency']);
        self::assertSame('night_per_room', $result['items'][3]['price']['basis']);
    }

    /** @return iterable<string, array{?bool, ?bool, string, list<string>}> */
    public static function triStateFilters(): iterable
    {
        yield 'true only' => [true, null, 'known', ['demo-cycle-lodge', 'demo-free-stay']];
        yield 'false only' => [false, null, 'known', ['demo-certified-hotel']];
        yield 'unknown only' => [null, null, 'unknown', ['demo-stay']];
    }

    #[DataProvider('triStateFilters')]
    public function testTriStateFeatureFiltersExcludeOtherStates(
        ?bool $bicycleParking,
        ?bool $publicTransportNearby,
        string $bicycleParkingState,
        array $expectedIds,
    ): void {
        $query = new AccommodationQuery(
            'demo-lyon',
            $bicycleParking,
            $publicTransportNearby,
            20,
            0,
            $bicycleParkingState,
        );

        $result = (new DemoAccommodationProvider())->search($query);

        self::assertSame($expectedIds, array_column($result['items'], 'id'));
    }

    public function testNoMatchesIsAnEmptyCoveredResponseAndUnknownDestinationIsOutOfCoverage(): void
    {
        $provider = new DemoAccommodationProvider();

        $empty = $provider->search(new AccommodationQuery('demo-lyon', false, false));
        self::assertSame('empty', $empty['status']);
        self::assertSame([], $empty['items']);
        self::assertSame(0, $empty['page']['total']);

        $outside = $provider->search(new AccommodationQuery('demo-nantes'));
        self::assertSame('out_of_coverage', $outside['status']);
        self::assertSame([], $outside['items']);
        self::assertSame([], $outside['sources']);
    }

    public function testVerifiedCertificationRequiresVerifiableProof(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Certification requires organization, reference URL and check date.');

        EnvironmentalEvidence::certification(
            'invalid-certification',
            'Certification sans preuve',
            null,
            null,
            '2026-01-01',
            '2027-01-01',
            null,
            'demo-accommodations',
        );
    }

    public function testExpiredCertificationCanNeverBeExposedAsVerified(): void
    {
        $evidence = EnvironmentalEvidence::certification(
            'expired-label',
            'Label environnemental de démonstration',
            'Organisme fictif',
            'https://example.test/certifications/expired-label',
            '2024-01-01',
            '2025-12-31',
            '2026-09-01',
            'demo-accommodations',
        );

        self::assertSame('expired', $evidence->toArray(new \DateTimeImmutable('2026-09-21'))['status']);
    }

    public function testCurrentCertificationIncludesProofAndValidity(): void
    {
        $result = (new DemoAccommodationProvider())->search(new AccommodationQuery('demo-lyon'));
        $certification = $result['items'][1]['evidence'][0];

        self::assertSame('certification', $certification['kind']);
        self::assertSame('demo', $certification['status']);
        self::assertNotEmpty($certification['organization']);
        self::assertNotEmpty($certification['referenceUrl']);
        self::assertNotEmpty($certification['checkedAt']);
        self::assertGreaterThanOrEqual('2026-09-21', $certification['validUntil']);
    }

    public function testKnownTransportProximityRequiresMeasuredDistance(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Known transport proximity requires a measured distance.');

        new Accommodation('invalid-distance', 'Invalid fixture', 'demo-lyon', null, true, null, null, []);
    }
}
