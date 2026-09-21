<?php

namespace App\Tests\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProblemTest extends WebTestCase
{
    #[DataProvider('plannedEndpoints')]
    public function testUnimplementedBusinessEndpointsAreHonest404Problems(string $method, string $path): void
    {
        $client = self::createClient();
        $client->request($method, $path);
        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        $problem = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('not_found', $problem['code']);
        self::assertSame($path, $problem['instance']);
        self::assertStringNotContainsString('/app/', $client->getResponse()->getContent());
    }

    public static function plannedEndpoints(): iterable
    {
        yield ['GET', '/api/v1/capabilities'];
        yield ['GET', '/api/v1/places'];
        yield ['POST', '/api/v1/journeys/search'];
        yield ['GET', '/api/v1/methodology'];
    }
}
