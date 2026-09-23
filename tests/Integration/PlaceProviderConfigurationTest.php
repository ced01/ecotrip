<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use App\Place\PostgresPlaceCatalog;
use App\Provider\PlaceProvider;
use PHPUnit\Framework\TestCase;

final class PlaceProviderConfigurationTest extends TestCase
{
    public function testExplicitFilBleuModeBuildsTheDatabaseProvider(): void
    {
        $previous = $_SERVER['ECOTRIP_PLACE_PROVIDER'] ?? null;
        $_SERVER['ECOTRIP_PLACE_PROVIDER'] = 'filbleu';
        $_ENV['ECOTRIP_PLACE_PROVIDER'] = 'filbleu';
        putenv('ECOTRIP_PLACE_PROVIDER=filbleu');
        $kernel = new Kernel('test', false);

        try {
            $kernel->boot();
            $testContainer = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(PostgresPlaceCatalog::class, $testContainer->get(PlaceProvider::class));
        } finally {
            $kernel->shutdown();
            if ($previous === null) {
                unset($_SERVER['ECOTRIP_PLACE_PROVIDER'], $_ENV['ECOTRIP_PLACE_PROVIDER']);
                putenv('ECOTRIP_PLACE_PROVIDER');
            } else {
                $_SERVER['ECOTRIP_PLACE_PROVIDER'] = $previous;
                $_ENV['ECOTRIP_PLACE_PROVIDER'] = $previous;
                putenv('ECOTRIP_PLACE_PROVIDER='.$previous);
            }
        }
    }
}
