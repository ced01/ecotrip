<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\DemoJourneyProvider;
use App\Journey\FilBleuJourneyProvider;
use App\Journey\JourneyProviderFactory;
use App\Kernel;
use App\Provider\JourneyProvider;
use PHPUnit\Framework\TestCase;

final class JourneyProviderConfigurationTest extends TestCase
{
    public function testExplicitFilBleuModeBuildsTheRealProvider(): void
    {
        $previous=$_SERVER['ECOTRIP_JOURNEY_PROVIDER']??null;
        $_SERVER['ECOTRIP_JOURNEY_PROVIDER']='filbleu'; $_ENV['ECOTRIP_JOURNEY_PROVIDER']='filbleu'; putenv('ECOTRIP_JOURNEY_PROVIDER=filbleu');
        $kernel=new Kernel('test',false);
        try { $kernel->boot(); self::assertInstanceOf(FilBleuJourneyProvider::class,$kernel->getContainer()->get('test.service_container')->get(JourneyProvider::class)); }
        finally { $kernel->shutdown(); if($previous===null){unset($_SERVER['ECOTRIP_JOURNEY_PROVIDER'],$_ENV['ECOTRIP_JOURNEY_PROVIDER']);putenv('ECOTRIP_JOURNEY_PROVIDER');}else{$_SERVER['ECOTRIP_JOURNEY_PROVIDER']=$previous;$_ENV['ECOTRIP_JOURNEY_PROVIDER']=$previous;putenv('ECOTRIP_JOURNEY_PROVIDER='.$previous);} }
    }

    public function testFactoryDefaultsAndUnknownSelectionFailsClosed(): void
    {
        $demo=(new \ReflectionClass(DemoJourneyProvider::class))->newInstanceWithoutConstructor();
        $real=(new \ReflectionClass(FilBleuJourneyProvider::class))->newInstanceWithoutConstructor();
        self::assertSame($demo,(new JourneyProviderFactory($demo,$real,'demo'))->create());
        $this->expectException(\InvalidArgumentException::class);
        (new JourneyProviderFactory($demo,$real,'unknown'))->create();
    }
}
