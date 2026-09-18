<?php
namespace App\Tests\Api;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
final class HealthTest extends WebTestCase
{
    public function testHealthIsLiveWithoutClaimingBusinessAvailability(): void
    {
        $client = self::createClient();
        $client->request('GET', '/health');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertSame(['status' => 'ok', 'service' => 'ecotrip'], json_decode($client->getResponse()->getContent(), true));
    }
}
