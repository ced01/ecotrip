<?php

namespace App\Tests\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProblemTest extends WebTestCase
{
    public function testUnknownBusinessEndpointIsAnHonest404Problem(): void
    {
        $method = 'GET';
        $path = '/api/v1/not-implemented';
        $client = self::createClient();
        $client->request($method, $path);
        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        $problem = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('not_found', $problem['code']);
        self::assertSame($path, $problem['instance']);
        self::assertStringNotContainsString('/app/', $client->getResponse()->getContent());
    }

}
