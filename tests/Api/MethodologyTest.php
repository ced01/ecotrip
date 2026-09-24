<?php

namespace App\Tests\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MethodologyTest extends WebTestCase
{
    public function testMethodologyIsImplementedAndMakesDemoAssumptionsVisible(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/methodology');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        $document = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('carbon-estimation-v2', $document['version']);
        self::assertSame('demo', $document['status']);
        self::assertSame(['operation', 'life_cycle'], $document['scopes']);
        self::assertSame('synthetic-tests', $document['sources'][0]['id']);
        self::assertSame('demo', $document['sources'][0]['dataStatus']);
        self::assertContains('Une distance ou émission inconnue vaut null, jamais zéro.', $document['rules']);
        self::assertSame('ademe-base-carbone', $document['sources'][1]['id']);
        self::assertContains('Zéro ou plusieurs facteurs applicables rendent l’émission indisponible; aucun fallback démo.', $document['rules']);
        self::assertSame(
            json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/examples/methodology.json'), true, 512, JSON_THROW_ON_ERROR),
            $document,
            'La réponse réelle doit rester identique à l’exemple OpenAPI validé.',
        );
    }
}
