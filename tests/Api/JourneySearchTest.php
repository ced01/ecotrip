<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class JourneySearchTest extends WebTestCase
{
    public function testCapabilitiesPlacesAndCoveredJourneyExposeHonestDemoData(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/capabilities');
        self::assertResponseIsSuccessful();
        $capabilities = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('demo', $capabilities['mode']);
        self::assertSame(['train', 'coach'], $capabilities['modes']);
        self::assertSame(9, $capabilities['limits']['maxTravelers']);

        $client->request('GET', '/api/v1/places?q=ly&limit=10&offset=0');
        self::assertResponseIsSuccessful();
        $places = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['demo-lyon'], array_column($places['items'], 'id'));
        self::assertSame('demo', $places['items'][0]['provenance']['status']);

        $client->jsonRequest('POST', '/api/v1/journeys/search', [
            'originId' => 'demo-paris',
            'destinationId' => 'demo-lyon',
            'departureDate' => '2027-01-15',
            'returnDate' => null,
            'travelers' => 2,
            'modes' => ['train', 'coach'],
        ]);
        self::assertResponseIsSuccessful();
        $result = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('demo', $result['dataMode']);
        self::assertSame('complete', $result['outbound']['status']);
        self::assertCount(2, $result['outbound']['itineraries']);
        self::assertSame('not_requested', $result['inbound']['status']);
        self::assertSame(['coach', 'train'], $this->motorizedModes($result['outbound']['itineraries']));
        foreach ($result['outbound']['itineraries'] as $itinerary) {
            self::assertSame(
                array_sum(array_column($itinerary['legs'], 'durationMinutes')) + array_sum(array_column($itinerary['legs'], 'waitingMinutes')),
                $itinerary['durationMinutes'],
            );
            self::assertSame('demo', $itinerary['dataStatus']);
            self::assertSame('demo', $itinerary['emissions']['status']);
            self::assertNotNull($itinerary['emissions']['totalDistanceKm']);
            self::assertSame($itinerary['emissions']['kgCO2ePerTraveler'] * 2, $itinerary['emissions']['kgCO2eGroup']);
        }
    }

    public function testModeFilterAndReturnAreCalculatedSeparately(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/v1/journeys/search', [
            'originId' => 'demo-paris',
            'destinationId' => 'demo-lyon',
            'departureDate' => '2027-01-15',
            'returnDate' => '2027-01-20',
            'travelers' => 1,
            'modes' => ['train'],
        ]);

        self::assertResponseIsSuccessful();
        $result = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $result['outbound']['itineraries']);
        self::assertCount(1, $result['inbound']['itineraries']);
        self::assertSame('outbound', $result['outbound']['itineraries'][0]['direction']);
        self::assertSame('2027-01-15', $result['outbound']['itineraries'][0]['requestedDate']);
        self::assertSame('demo-paris', $result['outbound']['itineraries'][0]['legs'][0]['originId']);
        self::assertSame('inbound', $result['inbound']['itineraries'][0]['direction']);
        self::assertSame('2027-01-20', $result['inbound']['itineraries'][0]['requestedDate']);
        self::assertSame('demo-lyon', $result['inbound']['itineraries'][0]['legs'][0]['originId']);
        self::assertNotSame($result['outbound']['itineraries'][0]['id'], $result['inbound']['itineraries'][0]['id']);
    }

    public function testEmptyAndOutOfCoverageAreDistinctSuccessfulResults(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/v1/journeys/search', $this->request(['flight']));
        self::assertResponseIsSuccessful();
        $empty = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('empty', $empty['outbound']['status']);

        $request = $this->request(['train']);
        $request['destinationId'] = 'demo-macon';
        $client->jsonRequest('POST', '/api/v1/journeys/search', $request);
        self::assertResponseIsSuccessful();
        $uncovered = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('out_of_coverage', $uncovered['outbound']['status']);
    }

    public function testValidationMediaTypeBodyLimitAndMalformedJsonUseProblemJson(): void
    {
        $client = self::createClient();

        $client->request('POST', '/api/v1/journeys/search', server: ['CONTENT_TYPE' => 'text/plain'], content: '{}');
        $this->assertProblem($client->getResponse(), 415, 'unsupported_media_type');

        $client->request('POST', '/api/v1/journeys/search', server: ['CONTENT_TYPE' => 'application/json'], content: '{');
        $this->assertProblem($client->getResponse(), 400, 'invalid_json');

        $client->request('POST', '/api/v1/journeys/search', server: ['CONTENT_TYPE' => 'application/json'], content: str_repeat(' ', 16385));
        $this->assertProblem($client->getResponse(), 413, 'payload_too_large');

        $invalid = $this->request(['train']);
        $invalid['destinationId'] = 'demo-paris';
        $invalid['travelers'] = 10;
        $client->jsonRequest('POST', '/api/v1/journeys/search', $invalid);
        $this->assertProblem($client->getResponse(), 422, 'validation_failed');
        $problem = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($problem['violations']);
    }

    public function testUnknownPlaceIsValidationErrorAndQuotaReturnsRetryAfter(): void
    {
        $client = self::createClient();
        $unknown = $this->request(['train']);
        $unknown['originId'] = 'unknown-place';
        $client->jsonRequest('POST', '/api/v1/journeys/search', $unknown);
        $this->assertProblem($client->getResponse(), 422, 'validation_failed');

        self::ensureKernelShutdown();
        $client = self::createClient();
        $client->disableReboot();
        for ($i = 0; $i < 20; ++$i) {
            $client->jsonRequest('POST', '/api/v1/journeys/search', $this->request(['train']));
            self::assertResponseIsSuccessful();
        }
        $client->jsonRequest('POST', '/api/v1/journeys/search', $this->request(['train']));
        $this->assertProblem($client->getResponse(), 429, 'rate_limited');
        self::assertResponseHeaderSame('retry-after', '60');
    }


    public function testImplementedResponsesMatchCheckedContractExamples(): void
    {
        $client = self::createClient();
        $root = dirname(__DIR__, 2).'/docs/examples/';

        $client->request('GET', '/api/v1/capabilities');
        self::assertSame(json_decode(file_get_contents($root.'capabilities.json'), true, 512, JSON_THROW_ON_ERROR), json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));
        $client->request('GET', '/api/v1/places');
        self::assertSame(json_decode(file_get_contents($root.'places.json'), true, 512, JSON_THROW_ON_ERROR), json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));
        $client->jsonRequest('POST', '/api/v1/journeys/search', $this->request(['train', 'coach']));
        self::assertSame(json_decode(file_get_contents($root.'journeys.json'), true, 512, JSON_THROW_ON_ERROR), json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function request(array $modes): array
    {
        return ['originId' => 'demo-paris', 'destinationId' => 'demo-lyon', 'departureDate' => '2027-01-15', 'returnDate' => null, 'travelers' => 1, 'modes' => $modes];
    }

    private function assertProblem($response, int $status, string $code): void
    {
        self::assertSame($status, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('content-type'));
        self::assertSame($code, json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)['code']);
    }

    /** @param list<array<string, mixed>> $itineraries @return list<string> */
    private function motorizedModes(array $itineraries): array
    {
        $modes = [];
        foreach ($itineraries as $itinerary) {
            foreach ($itinerary['legs'] as $leg) {
                if (in_array($leg['mode'], ['train', 'coach'], true)) {
                    $modes[] = $leg['mode'];
                    break;
                }
            }
        }
        sort($modes);
        return $modes;
    }
}
