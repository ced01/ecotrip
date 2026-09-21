<?php

namespace App\Tests\Integration;

use App\Environmental\EmissionComparator;
use App\Environmental\EmissionEstimator;
use App\Environmental\EmissionFactorRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmissionEstimatorTest extends TestCase
{
    public function testPassengerKilometreArithmeticAndGroupTotalAreAuditable(): void
    {
        $estimator = new EmissionEstimator($this->repository([
            self::factor('rail-test-v1', 0.02, 'kgCO2e/passenger-km', 'life_cycle'),
        ]));

        $estimate = $estimator->estimate([
            ['id' => 'leg-rail', 'mode' => 'train', 'subtype' => null, 'distanceKm' => 125.0],
        ], 3, 'FR', new \DateTimeImmutable('2027-01-15'), 'demo');

        self::assertSame('demo', $estimate['status']);
        self::assertSame(2.5, $estimate['kgCO2ePerTraveler']);
        self::assertSame(7.5, $estimate['kgCO2eGroup']);
        self::assertSame(125.0, $estimate['coveredDistanceKm']);
        self::assertSame(125.0, $estimate['totalDistanceKm']);
        self::assertSame('rail-test-v1', $estimate['legs'][0]['factorId']);
        self::assertSame(self::factor('rail-test-v1', 0.02, 'kgCO2e/passenger-km', 'life_cycle'), $estimate['factors'][0]);
        self::assertTrue($estimate['comparable']);
        self::assertNotNull($estimate['comparisonKey']);
    }

    public function testVehicleKilometreUsesExplicitOccupancyForIndividualAndGroup(): void
    {
        $factor = self::factor('coach-test-v1', 1.2, 'kgCO2e/vehicle-km', 'operation', 40.0);
        $estimator = new EmissionEstimator($this->repository([$factor]));

        $estimate = $estimator->estimate([
            ['id' => 'leg-coach', 'mode' => 'coach', 'subtype' => null, 'distanceKm' => 100.0],
        ], 4, 'FR', new \DateTimeImmutable('2027-01-15'), 'demo');

        self::assertSame(3.0, $estimate['kgCO2ePerTraveler']);
        self::assertSame(12.0, $estimate['kgCO2eGroup']);
        self::assertContains('Occupation moyenne du facteur coach-test-v1 : 40 voyageurs par véhicule.', $estimate['assumptions']);
    }

    public function testDemoProvenanceCannotBeMaskedByVerifiedFactorOrComparedWithRealData(): void
    {
        $factor = self::factor('rail-verified-fixture', 0.015, 'kgCO2e/passenger-km', 'life_cycle');
        $factor['status'] = 'verified';
        $factor['sourceId'] = 'verified-repository-fixture';

        $demo = $this->singleLegEstimate($factor, 'demo');
        $real = $this->singleLegEstimate($factor, 'real');

        self::assertSame('demo', $demo['status']);
        self::assertSame('demo', $demo['legs'][0]['status']);
        self::assertSame('Calcul de démonstration (provenance des données du trajet).', $demo['legs'][0]['reason']);
        self::assertTrue($demo['comparable']);
        self::assertStringContainsString('|demo|verified', $demo['comparisonKey']);

        self::assertSame('complete', $real['status']);
        self::assertSame('complete', $real['legs'][0]['status']);
        self::assertNull($real['legs'][0]['reason']);
        self::assertTrue($real['comparable']);
        self::assertStringContainsString('|real|verified', $real['comparisonKey']);
        self::assertNotSame($real['comparisonKey'], $demo['comparisonKey']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Estimations non comparables');
        (new EmissionComparator())->compare($demo, $real);
    }

    public function testTotalsAreRoundedOnlyAfterAggregatingUnroundedLegAmounts(): void
    {
        $factor = self::factor('rail-small-v1', 0.0000000000004, 'kgCO2e/passenger-km', 'life_cycle');
        $estimator = new EmissionEstimator($this->repository([$factor]));

        $estimate = $estimator->estimate([
            ['id' => 'leg-1', 'mode' => 'train', 'subtype' => null, 'distanceKm' => 1.0],
            ['id' => 'leg-2', 'mode' => 'train', 'subtype' => null, 'distanceKm' => 1.0],
            ['id' => 'leg-3', 'mode' => 'train', 'subtype' => null, 'distanceKm' => 1.0],
        ], 3, 'FR', new \DateTimeImmutable('2027-01-15'), 'demo');

        self::assertSame(0.000000000001, $estimate['kgCO2ePerTraveler']);
        self::assertSame(0.000000000004, $estimate['kgCO2eGroup']);
        self::assertSame([0.0, 0.0, 0.0], array_column($estimate['legs'], 'kgCO2ePerTraveler'));
    }

    public function testUnknownDistanceIsNullAndNeverConfusedWithMeasuredZero(): void
    {
        $estimator = new EmissionEstimator($this->repository([
            self::factor('walk-test-v1', 0.0, 'kgCO2e/passenger-km', 'operation'),
        ]));

        $unknown = $estimator->estimate([
            ['id' => 'leg-unknown', 'mode' => 'walk', 'subtype' => null, 'distanceKm' => null],
        ], 1, 'FR', new \DateTimeImmutable('2027-01-15'), 'demo');
        $zero = $estimator->estimate([
            ['id' => 'leg-zero', 'mode' => 'walk', 'subtype' => null, 'distanceKm' => 0.0],
        ], 1, 'FR', new \DateTimeImmutable('2027-01-15'), 'demo');

        self::assertSame('unavailable', $unknown['status']);
        self::assertNull($unknown['kgCO2ePerTraveler']);
        self::assertNull($unknown['kgCO2eGroup']);
        self::assertNull($unknown['totalDistanceKm']);
        self::assertSame('unavailable', $unknown['legs'][0]['status']);
        self::assertSame('demo', $zero['status']);
        self::assertSame(0.0, $zero['kgCO2ePerTraveler']);
        self::assertSame(0.0, $zero['kgCO2eGroup']);
        self::assertSame(0.0, $zero['totalDistanceKm']);
    }

    public function testMissingFactorKeepsDemoProvenanceVisibleOnPartialCoverage(): void
    {
        $repository = new class implements EmissionFactorRepository {
            public function findCandidates(string $mode, ?string $subtype, string $geography, \DateTimeImmutable $date): array
            {
                if ($mode !== 'train') {
                    return [];
                }

                $factor = EmissionEstimatorTest::factor('rail-test-v1', 0.01, 'kgCO2e/passenger-km', 'life_cycle');
                $factor['status'] = 'verified';
                $factor['sourceId'] = 'verified-repository-fixture';

                return [$factor];
            }
        };
        $estimate = (new EmissionEstimator($repository))->estimate([
            ['id' => 'covered', 'mode' => 'train', 'subtype' => null, 'distanceKm' => 100.0],
            ['id' => 'missing', 'mode' => 'flight', 'subtype' => null, 'distanceKm' => 500.0],
        ], 2, 'FR', new \DateTimeImmutable('2027-01-15'), 'demo');

        self::assertSame('demo', $estimate['status']);
        self::assertSame(1.0, $estimate['kgCO2ePerTraveler']);
        self::assertSame(2.0, $estimate['kgCO2eGroup']);
        self::assertSame(100.0, $estimate['coveredDistanceKm']);
        self::assertSame(600.0, $estimate['totalDistanceKm']);
        self::assertFalse($estimate['comparable']);
        self::assertNull($estimate['comparisonKey']);
        self::assertSame('demo', $estimate['legs'][0]['status']);
        self::assertSame('unavailable', $estimate['legs'][1]['status']);
        self::assertSame('Aucun facteur applicable.', $estimate['legs'][1]['reason']);

        $real = (new EmissionEstimator($repository))->estimate([
            ['id' => 'covered', 'mode' => 'train', 'subtype' => null, 'distanceKm' => 100.0],
            ['id' => 'missing', 'mode' => 'flight', 'subtype' => null, 'distanceKm' => 500.0],
        ], 2, 'FR', new \DateTimeImmutable('2027-01-15'), 'real');

        self::assertSame('partial', $real['status']);
        self::assertSame('complete', $real['legs'][0]['status']);
        self::assertNull($real['legs'][0]['reason']);
    }

    public function testIncompatibleScopesAreExplicitlyNotComparableAndComparisonIsRefused(): void
    {
        $operation = $this->singleLegEstimate(self::factor('rail-op-v1', 0.01, 'kgCO2e/passenger-km', 'operation'));
        $lifeCycle = $this->singleLegEstimate(self::factor('rail-lca-v1', 0.02, 'kgCO2e/passenger-km', 'life_cycle'));

        self::assertTrue($operation['comparable']);
        self::assertTrue($lifeCycle['comparable']);
        self::assertNotSame($operation['comparisonKey'], $lifeCycle['comparisonKey']);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Estimations non comparables');
        (new EmissionComparator())->compare($operation, $lifeCycle);
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidInputsAreRejected(array $legs, int $travelers): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EmissionEstimator($this->repository([])))->estimate($legs, $travelers, 'FR', new \DateTimeImmutable('2027-01-15'), 'real');
    }

    public static function invalidInputs(): iterable
    {
        yield 'no traveler' => [[], 0];
        yield 'negative distance' => [[['id' => 'leg', 'mode' => 'train', 'subtype' => null, 'distanceKm' => -1.0]], 1];
    }

    /** @param list<array<string, mixed>> $factors */
    private function repository(array $factors): EmissionFactorRepository
    {
        return new class($factors) implements EmissionFactorRepository {
            public function __construct(private readonly array $factors) {}
            public function findCandidates(string $mode, ?string $subtype, string $geography, \DateTimeImmutable $date): array { return $this->factors; }
        };
    }

    /** @return array<string, mixed> */
    public static function factor(string $id, float $value, string $unit, string $scope, ?float $occupancy = null): array
    {
        return ['id' => $id, 'value' => $value, 'unit' => $unit, 'mode' => str_starts_with($id, 'coach') ? 'coach' : (str_starts_with($id, 'walk') ? 'walk' : 'train'), 'subtype' => null, 'geography' => 'FR', 'validFrom' => '2026-01-01', 'validUntil' => null, 'scope' => $scope, 'occupancy' => $occupancy, 'version' => 'test-v1', 'sourceId' => 'synthetic-tests', 'status' => 'synthetic_test'];
    }

    private function singleLegEstimate(array $factor, string $dataStatus = 'demo'): array
    {
        return (new EmissionEstimator($this->repository([$factor])))->estimate([
            ['id' => 'leg', 'mode' => 'train', 'subtype' => null, 'distanceKm' => 100.0],
        ], 1, 'FR', new \DateTimeImmutable('2027-01-15'), $dataStatus);
    }
}
