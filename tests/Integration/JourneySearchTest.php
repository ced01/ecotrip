<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Provider\JourneyProvider;
use App\Provider\ProviderUnavailable;
use App\Trip\DirectionResult;
use App\Trip\DirectionStatus;
use App\Trip\JourneyQuery;
use App\Trip\JourneySearch;
use PHPUnit\Framework\TestCase;

final class JourneySearchTest extends TestCase
{
    public function testOneDirectionFailureStaysExplicitWhileOtherDirectionSucceeds(): void
    {
        $provider = new class implements JourneyProvider {
            public function search(JourneyQuery $query): DirectionResult
            {
                if ($query->originId === 'demo-lyon') throw new ProviderUnavailable('secret upstream detail');
                return new DirectionResult(DirectionStatus::Complete, [['id' => 'outbound-fixture']]);
            }
        };

        $result = (new JourneySearch($provider))->search($this->roundTrip());
        self::assertSame('complete', $result['outbound']['status']);
        self::assertSame('unavailable', $result['inbound']['status']);
        self::assertSame([], $result['inbound']['itineraries']);
        self::assertStringNotContainsString('secret', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testTotalProviderFailureRaisesUnavailableInsteadOfDemoFallback(): void
    {
        $provider = new class implements JourneyProvider {
            public function search(JourneyQuery $query): DirectionResult { throw new ProviderUnavailable('failure'); }
        };

        $this->expectException(ProviderUnavailable::class);
        (new JourneySearch($provider))->search($this->roundTrip());
    }

    public function testOneWayProviderFailureIsAlsoATotalFailure(): void
    {
        $provider = new class implements JourneyProvider {
            public function search(JourneyQuery $query): DirectionResult { throw new ProviderUnavailable('failure'); }
        };
        $request = $this->roundTrip();
        $request['returnDate'] = null;

        $this->expectException(ProviderUnavailable::class);
        (new JourneySearch($provider))->search($request);
    }

    /** @return array<string, mixed> */
    private function roundTrip(): array
    {
        return ['originId' => 'demo-paris', 'destinationId' => 'demo-lyon', 'departureDate' => new \DateTimeImmutable('2027-01-15'), 'returnDate' => new \DateTimeImmutable('2027-01-20'), 'travelers' => 1, 'modes' => ['train']];
    }
}
