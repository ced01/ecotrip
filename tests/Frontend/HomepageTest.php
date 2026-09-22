<?php

namespace App\Tests\Frontend;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomepageTest extends WebTestCase
{
    public function testHomepageExposesAnAccessibleContractBackedSearchForm(): void
    {
        $client = self::createClient(['debug' => true]);
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Voyager mieux');
        self::assertSelectorExists('main#contenu');
        self::assertSelectorExists('form[data-search-form]');
        self::assertSelectorExists('label[for="origin"]');
        self::assertSelectorExists('select#origin[name="originId"][required]');
        self::assertSelectorExists('label[for="destination"]');
        self::assertSelectorExists('select#destination[name="destinationId"][required]');
        self::assertSelectorExists('input#departure-date[name="departureDate"][type="date"][required]');
        self::assertSelectorExists('input#return-date[name="returnDate"][type="date"]');
        self::assertSelectorExists('input#travelers[name="travelers"][type="number"][min="1"][max="9"][required]');
        self::assertSelectorCount(7, 'input[name="modes[]"][type="checkbox"]');
        self::assertSelectorExists('[role="status"][aria-live="polite"]');
        self::assertSelectorExists('[role="alert"][hidden]');
        self::assertSelectorExists('select[data-sort]');
        self::assertSelectorExists('[data-results][aria-live="polite"]');
        self::assertSelectorExists('[data-methodology]');
        self::assertSelectorTextContains('[data-demo-notice]', 'Démonstration');
        self::assertStringContainsString(
            '"ecotrip/search-core"',
            (string) $client->getResponse()->getContent(),
            'The import map must expose the dependency imported by search-app.mjs.',
        );
        self::assertStringContainsString(
            '"ecotrip/search-presentation"',
            (string) $client->getResponse()->getContent(),
            'The import map must expose the presentation dependency imported by search-app.mjs.',
        );

        $options = $crawler->filter('#origin option[value]')->each(static fn ($node): string => $node->attr('value'));
        self::assertContains('demo-paris', $options);
        self::assertContains('demo-lyon', $options);

        self::assertSelectorNotExists('#journeys-fixture');
        self::assertStringContainsString('/api/v1/journeys/search', (string) $client->getResponse()->getContent());
    }

    public function testImplementedJourneyEndpointRejectsAnInvalidRequestWithAContractProblem(): void
    {
        $client = self::createClient(['debug' => true]);
        $client->request('POST', '/api/v1/journeys/search', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        $problem = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('validation_failed', $problem['code']);
    }
}
