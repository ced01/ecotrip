<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AccommodationTest extends WebTestCase
{
    public function testCatalogResponseIsExplicitlyDemoAndMakesNoAvailabilityOrBookingClaim(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/accommodations?destinationId=demo-lyon');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('complete', $payload['status']);
        self::assertSame('demo', $payload['sources'][0]['dataStatus']);
        self::assertStringContainsString('synthétique', $payload['sources'][0]['publisher']);
        self::assertArrayNotHasKey('availability', $payload['items'][0]);
        self::assertArrayNotHasKey('booking', $payload['items'][0]);
    }

    public function testUnknownFilterSelectsUnknownRatherThanFalse(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/accommodations?destinationId=demo-lyon&publicTransportNearby=unknown');

        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['demo-stay', 'demo-free-stay'], array_column($payload['items'], 'id'));
        foreach ($payload['items'] as $item) {
            self::assertNull($item['features']['publicTransportNearby']);
        }
    }

    public function testFalseFilterDoesNotIncludeUnknownAndSupportsPagination(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/accommodations?destinationId=demo-lyon&publicTransportNearby=false&limit=1&offset=0');

        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['demo-cycle-lodge'], array_column($payload['items'], 'id'));
        self::assertSame(['limit' => 1, 'offset' => 0, 'total' => 1], $payload['page']);
    }

    public function testValidFiltersCanReturnAnEmptyResponse(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/accommodations?destinationId=demo-lyon&bicycleParking=false&publicTransportNearby=false');

        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('empty', $payload['status']);
        self::assertSame([], $payload['items']);
        self::assertSame(0, $payload['page']['total']);
    }

    public function testUnknownDestinationIsOutOfCoverageRatherThanNotFound(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/accommodations?destinationId=demo-nantes');

        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('out_of_coverage', $payload['status']);
        self::assertSame([], $payload['items']);
    }

    public function testInvalidOrMissingQueryParametersReturnContractProblem(): void
    {
        $client = self::createClient();

        foreach ([
            '/api/v1/accommodations',
            '/api/v1/accommodations?destinationId=INVALID!',
            '/api/v1/accommodations?destinationId=demo-lyon&bicycleParking=yes',
            '/api/v1/accommodations?destinationId=demo-lyon&limit=0',
            '/api/v1/accommodations?destinationId=demo-lyon&offset=10001',
        ] as $uri) {
            $client->request('GET', $uri);
            self::assertResponseStatusCodeSame(422, $uri);
            self::assertResponseHeaderSame('content-type', 'application/problem+json');
            $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('validation_failed', $payload['code']);
            self::assertNotEmpty($payload['violations']);
        }
    }
}
