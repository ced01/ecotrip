<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\ImportFilBleuPlacesCommand;
use App\Place\FilBleuGtfsImporter;
use App\Place\PostgresPlaceReferenceResolver;
use App\Place\PostgresPlaceCatalog;
use App\Journey\FilBleuJourneyProvider;
use App\Journey\PostgresJourneyScheduleRepository;
use App\Trip\DirectionStatus;
use App\Trip\JourneyQuery;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RealPlaceCatalogTest extends KernelTestCase
{
    private Connection $db;
    private string $schema;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get('doctrine')->getConnection();
        $this->schema = 'test_places_'.bin2hex(random_bytes(5));
        $this->db->executeStatement('CREATE SCHEMA '.$this->schema);
        $this->db->executeStatement('SET search_path TO '.$this->schema);
        foreach (glob(dirname(__DIR__, 2).'/migrations/Version*.php') ?: [] as $file) {
            $class = 'DoctrineMigrations\\'.basename($file, '.php');
            $migration = new $class($this->db, new \Psr\Log\NullLogger());
            $migration->up(new \Doctrine\DBAL\Schema\Schema());
            foreach ($migration->getSql() as $query) {
                $this->db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement('SET search_path TO public');
        $this->db->executeStatement('DROP SCHEMA '.$this->schema.' CASCADE');
        parent::tearDown();
    }

    #[Test]
    public function verifiedDirectJourneyUsesCalendarAndOpaqueEcoTripReferences(): void
    {
        $zip = $this->documentedFixtureZip();
        (new FilBleuGtfsImporter($this->db))->import($zip, '10048_164382514', hash_file('sha256', $zip));
        $provider = new FilBleuJourneyProvider(new PostgresPlaceReferenceResolver($this->db), new PostgresJourneyScheduleRepository($this->db));
        $origin = FilBleuGtfsImporter::internalId('TTR:AC-GATO');
        $destination = FilBleuGtfsImporter::internalId('TTR:AC-JJAU');

        $active = $provider->search(new JourneyQuery($origin, $destination, new \DateTimeImmutable('2026-09-23', new \DateTimeZone('Europe/Paris')), 1, ['public_transport']));
        self::assertSame(DirectionStatus::Complete, $active->status);
        self::assertSame('2026-09-23T05:22:00+02:00', $active->itineraries[0]['legs'][0]['schedule']['departureAt']);
        self::assertSame('2026-09-23T05:24:00+02:00', $active->itineraries[0]['legs'][0]['schedule']['arrivalAt']);
        self::assertSame($origin, $active->itineraries[0]['legs'][0]['originId']);
        self::assertStringNotContainsString('TTR:', json_encode($active->itineraries, JSON_THROW_ON_ERROR));

        $weekend = $provider->search(new JourneyQuery($origin, $destination, new \DateTimeImmutable('2026-09-20', new \DateTimeZone('Europe/Paris')), 1, ['public_transport']));
        self::assertSame(DirectionStatus::Empty, $weekend->status);
    }

    #[Test]
    public function serviceExceptionsAndPickupDropOffRulesAreApplied(): void
    {
        $zip = $this->documentedFixtureZip();
        $import = (new FilBleuGtfsImporter($this->db))->import($zip, '10048_164382514', hash_file('sha256', $zip));
        $provider = new FilBleuJourneyProvider(new PostgresPlaceReferenceResolver($this->db), new PostgresJourneyScheduleRepository($this->db));
        $origin = FilBleuGtfsImporter::internalId('TTR:AC-GATO');
        $destination = FilBleuGtfsImporter::internalId('TTR:AC-JJAU');
        $serviceId = (string) $this->db->fetchOne('SELECT external_id FROM gtfs_service WHERE import_id=:import', ['import' => $import->importId]);

        $this->db->insert('gtfs_service_exception', [
            'import_id' => $import->importId,
            'service_id' => $serviceId,
            'service_date' => '2026-09-23',
            'exception_type' => 2,
        ]);
        self::assertSame(DirectionStatus::Empty, $provider->search(new JourneyQuery($origin, $destination, new \DateTimeImmutable('2026-09-23'), 1, ['public_transport']))->status);

        $this->db->insert('gtfs_service_exception', [
            'import_id' => $import->importId,
            'service_id' => $serviceId,
            'service_date' => '2026-09-20',
            'exception_type' => 1,
        ]);
        self::assertSame(DirectionStatus::Complete, $provider->search(new JourneyQuery($origin, $destination, new \DateTimeImmutable('2026-09-20'), 1, ['public_transport']))->status);

        $this->db->executeStatement('UPDATE gtfs_stop_time SET pickup_type=1 WHERE import_id=:import AND stop_id=:stop', ['import' => $import->importId, 'stop' => 'TTR:GAVNB-1']);
        self::assertSame(DirectionStatus::Empty, $provider->search(new JourneyQuery($origin, $destination, new \DateTimeImmutable('2026-09-20'), 1, ['public_transport']))->status);
        $this->db->executeStatement('UPDATE gtfs_stop_time SET pickup_type=0 WHERE import_id=:import AND stop_id=:stop', ['import' => $import->importId, 'stop' => 'TTR:GAVNB-1']);
        $this->db->executeStatement('UPDATE gtfs_stop_time SET drop_off_type=1 WHERE import_id=:import AND stop_id=:stop', ['import' => $import->importId, 'stop' => 'TTR:JJOUB-1']);
        self::assertSame(DirectionStatus::Empty, $provider->search(new JourneyQuery($origin, $destination, new \DateTimeImmutable('2026-09-20'), 1, ['public_transport']))->status);
    }

    #[Test]
    public function importIsIdempotentSearchableAndResolvable(): void
    {
        $zip = $this->documentedFixtureZip();
        $sha = hash_file('sha256', $zip);
        $importer = new FilBleuGtfsImporter($this->db);
        $first = $importer->import($zip, '10048_164382514', $sha);
        $second = $importer->import($zip, '10048_164382514', $sha);

        self::assertSame(3, $first->stationCount);
        self::assertSame(9, $first->referenceCount);
        self::assertSame(3, $second->stationCount);
        self::assertSame(3, (int) $this->db->fetchOne('SELECT count(*) FROM place'));
        self::assertSame(9, (int) $this->db->fetchOne('SELECT count(*) FROM place_external_reference'));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT count(*) FROM place_import'));

        $catalog = new PostgresPlaceCatalog($this->db);
        $result = $catalog->search('jean jaures', 10, 0);
        self::assertSame(1, $result['page']['total']);
        self::assertSame('Jean Jaurès', $result['items'][0]['name']);
        self::assertSame('verified', $result['items'][0]['provenance']['status']);
        self::assertSame('Licence Ouverte 2.0', $result['sources'][0]['license']);
        self::assertSame('10048_164382514', $result['sources'][0]['version']);

        $id = $result['items'][0]['id'];
        self::assertMatchesRegularExpression('/^[a-z0-9][a-z0-9_-]{0,63}$/', $id);
        $resolver = new PostgresPlaceReferenceResolver($this->db);
        self::assertSame('TTR:AC-JJAU', $resolver->toExternalId($id, 'filbleu-gtfs'));
        self::assertSame($id, $resolver->toInternalId('filbleu-gtfs', 'TTR:AC-JJAU'));
        self::assertNull($resolver->toInternalId('filbleu-gtfs', 'missing'));
        self::assertTrue($catalog->has($id));
    }

    #[Test]
    public function identitySurvivesUpdatesDeactivationAndReactivationRegardlessOfOrder(): void
    {
        $importer = new FilBleuGtfsImporter($this->db);
        $a = $this->fixtureZip([
            ['station-b', 'Béta', '47.2', '0.7', '1', ''],
            ['station-a', 'Alpha', '47.1', '0.6', '1', ''],
        ], 'v1');
        $importer->import($a, 'v1', hash_file('sha256', $a));
        $ids = $this->db->fetchAllKeyValue('SELECT name, id FROM place ORDER BY name');

        $b = $this->fixtureZip([['station-a', 'Alpha renommée', '48.1', '1.6', '1', '']], 'v2');
        $importer->import($b, 'v2', hash_file('sha256', $b));
        self::assertSame($ids['Alpha'], $this->db->fetchOne("SELECT id FROM place WHERE name = 'Alpha renommée'"));
        self::assertFalse((new PostgresPlaceCatalog($this->db))->has($ids['Béta']));

        $c = $this->fixtureZip([
            ['station-a', 'Alpha renommée', '48.1', '1.6', '1', ''],
            ['station-b', 'Béta', '47.2', '0.7', '1', ''],
        ], 'v3');
        $importer->import($c, 'v3', hash_file('sha256', $c));
        self::assertSame($ids['Béta'], (new PostgresPlaceReferenceResolver($this->db))->toInternalId('filbleu-gtfs', 'station-b'));
        self::assertTrue((new PostgresPlaceCatalog($this->db))->has($ids['Béta']));
    }

    #[Test]
    public function invalidChecksumDuplicateOrIncompleteZipRollsBack(): void
    {
        $importer = new FilBleuGtfsImporter($this->db);
        $valid = $this->fixtureZip([['station-a', 'Alpha', '47.1', '0.6', '1', '']]);
        foreach ([
            fn () => $importer->import($valid, 'v1', str_repeat('0', 64)),
            function () use ($importer): void {
                $zip = $this->fixtureZip([
                    ['station-a', 'Alpha', '47.1', '0.6', '1', ''],
                    ['station-a', 'Different', '47.1', '0.6', '1', ''],
                ]);
                $importer->import($zip, 'fixture-v1', hash_file('sha256', $zip));
            },
            function () use ($importer): void {
                $zip = $this->fixtureZip([], 'fixture-v1', false);
                $importer->import($zip, 'fixture-v1', hash_file('sha256', $zip));
            },
        ] as $failure) {
            try {
                $failure();
                self::fail('Import should fail closed.');
            } catch (\InvalidArgumentException) {
                self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM place'));
            }
        }
    }

    #[Test]
    public function orphanIntermediateStopRejectsTheImportAndRollsBackEveryMutation(): void
    {
        $importer = new FilBleuGtfsImporter($this->db);
        $valid = $this->documentedFixtureZip();
        $importer->import($valid, '10048_164382514', hash_file('sha256', $valid));
        $before = $this->databaseCounts();

        $fixtureDirectory = dirname(__DIR__).'/Fixtures/filbleu-minimal';
        $corrupt = $this->documentedFixtureZip();
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($corrupt));
        $stops = (string) file_get_contents($fixtureDirectory.'/stops.txt');
        $stops .= "\"orphan-platform\",\"Orphan\",\"47.4\",\"0.7\",\"0\",\"\",\"Europe/Paris\"\n";
        $trip = '#JDD-1012#2056848#1991409-Hiver-Sco_14Sept26#0#SEMAINE#272179';
        $stopTimes = "trip_id,arrival_time,departure_time,stop_id,stop_sequence,pickup_type,drop_off_type\n";
        $stopTimes .= "\"$trip\",\"05:22:00\",\"05:22:00\",\"TTR:GAVNB-1\",21,0,0\n";
        $stopTimes .= "\"$trip\",\"05:23:00\",\"05:23:00\",\"orphan-platform\",22,0,0\n";
        $stopTimes .= "\"$trip\",\"05:24:00\",\"05:24:00\",\"TTR:JJOUB-1\",23,0,0\n";
        self::assertTrue($zip->addFromString('stops.txt', $stops));
        self::assertTrue($zip->addFromString('stop_times.txt', $stopTimes));
        $zip->close();

        try {
            $importer->import($corrupt, '10048_164382514', hash_file('sha256', $corrupt));
            self::fail('An exploited trip with an orphan physical stop must fail closed.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('commercial station', $error->getMessage());
        }

        self::assertSame($before, $this->databaseCounts());
        self::assertSame(9, (int) $this->db->fetchOne("SELECT count(*) FROM place_external_reference WHERE active=TRUE"));
    }

    #[Test]
    public function unknownRouteRejectsImportWithoutPublishingASnapshot(): void
    {
        $zipPath = $this->documentedFixtureZip();
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath));
        self::assertTrue($zip->addFromString('trips.txt', "route_id,service_id,trip_id,trip_headsign\nunknown-route,service,trip,Fixture\n"));
        $zip->close();

        $this->expectException(\InvalidArgumentException::class);
        try {
            (new FilBleuGtfsImporter($this->db))->import($zipPath, '10048_164382514', hash_file('sha256', $zipPath));
        } finally {
            self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM place_import'));
            self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM place'));
        }
    }

    #[Test]
    public function absentJourneyImportIsUnavailableRatherThanEmpty(): void
    {
        $repository = new PostgresJourneyScheduleRepository($this->db);

        $this->expectException(\App\Provider\ProviderUnavailable::class);
        $repository->direct('station-a', 'station-b', new \DateTimeImmutable('2026-09-23'));
    }

    #[Test]
    public function corruptedJourneyImportIsUnavailableRatherThanEmpty(): void
    {
        $zip = $this->documentedFixtureZip();
        (new FilBleuGtfsImporter($this->db))->import($zip, '10048_164382514', hash_file('sha256', $zip));
        $this->db->update('data_source', ['license'=>'unexpected'], ['id'=>FilBleuGtfsImporter::SOURCE_ID]);

        $this->expectException(\App\Provider\ProviderUnavailable::class);
        (new PostgresJourneyScheduleRepository($this->db))->direct('TTR:AC-GATO', 'TTR:AC-JJAU', new \DateTimeImmutable('2026-09-23'));
    }

    #[Test]
    public function repositorySortLimitAndPublicIdsStayStableForRepeatedStationPairs(): void
    {
        $zip = $this->documentedFixtureZip();
        $import = (new FilBleuGtfsImporter($this->db))->import($zip, '10048_164382514', hash_file('sha256', $zip));
        $trip = (string) $this->db->fetchOne('SELECT external_id FROM gtfs_trip WHERE import_id=:import', ['import'=>$import->importId]);
        $this->db->executeStatement('DELETE FROM gtfs_stop_time WHERE import_id=:import', ['import'=>$import->importId]);
        for ($pair = 0; $pair < 11; ++$pair) {
            $departureSequence = $pair * 2 + 1;
            $this->db->insert('gtfs_stop_time', ['import_id'=>$import->importId,'trip_id'=>$trip,'stop_id'=>'TTR:GAVNB-1','stop_sequence'=>$departureSequence,'arrival_seconds'=>21600,'departure_seconds'=>21600,'pickup_type'=>0,'drop_off_type'=>0]);
            $this->db->insert('gtfs_stop_time', ['import_id'=>$import->importId,'trip_id'=>$trip,'stop_id'=>'TTR:JJOUB-1','stop_sequence'=>$departureSequence + 1,'arrival_seconds'=>21900,'departure_seconds'=>21900,'pickup_type'=>0,'drop_off_type'=>0]);
        }

        $repository = new PostgresJourneyScheduleRepository($this->db);
        $rows = $repository->direct('TTR:AC-GATO', 'TTR:AC-JJAU', new \DateTimeImmutable('2026-09-23'));
        self::assertCount(20, $rows);
        $sequencePairs = array_map(static fn(array $row): array => [$row['departureSequence'], $row['arrivalSequence']], $rows);
        $expected = [];
        for ($departure = 1; $departure <= 21 && count($expected) < 20; $departure += 2) {
            for ($arrival = $departure + 1; $arrival <= 22 && count($expected) < 20; $arrival += 2) $expected[] = [$departure, $arrival];
        }
        self::assertSame($expected, $sequencePairs);

        $provider = new FilBleuJourneyProvider(new PostgresPlaceReferenceResolver($this->db), $repository);
        $query = new JourneyQuery(FilBleuGtfsImporter::internalId('TTR:AC-GATO'), FilBleuGtfsImporter::internalId('TTR:AC-JJAU'), new \DateTimeImmutable('2026-09-23'), 1, ['public_transport']);
        $firstIds = array_column($provider->search($query)->itineraries, 'id');
        $secondIds = array_column($provider->search($query)->itineraries, 'id');
        self::assertSame($firstIds, $secondIds);
        self::assertCount(20, array_unique($firstIds));
    }

    #[Test]
    public function consoleCommandImportsTheRequestedFeedVersionInsteadOfTriggeringSymfonyVersionOutput(): void
    {
        $zip = $this->fixtureZip([
            ['TTR:AC-GATO', 'Gare de Tours', '47.38906503', '0.69336917', '1', ''],
        ]);
        $tester = new CommandTester(new ImportFilBleuPlacesCommand(new FilBleuGtfsImporter($this->db)));

        $status = $tester->execute([
            '--file' => $zip,
            '--feed-version' => 'fixture-v1',
            '--sha256' => hash_file('sha256', $zip),
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Imported feed fixture-v1', $tester->getDisplay());
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM place_import'));
    }

    /** @return array<string, int> */
    private function databaseCounts(): array
    {
        $tables = ['data_source', 'place_import', 'place', 'place_external_reference', 'gtfs_route', 'gtfs_service', 'gtfs_service_exception', 'gtfs_trip', 'gtfs_stop_time'];
        $counts = [];
        foreach ($tables as $table) $counts[$table] = (int) $this->db->fetchOne('SELECT count(*) FROM '.$table);

        return $counts;
    }

    private function documentedFixtureZip(): string
    {
        $fixtureDirectory = dirname(__DIR__).'/Fixtures/filbleu-minimal';
        $path = tempnam(sys_get_temp_dir(), 'filbleu_fixture_').'.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach (['feed_infos.txt', 'stops.txt', 'routes.txt', 'calendar.txt', 'calendar_dates.txt', 'trips.txt', 'stop_times.txt'] as $name) {
            self::assertTrue($zip->addFile($fixtureDirectory.'/'.$name, $name));
        }
        $zip->close();

        return $path;
    }

    /** @param list<array{string,string,string,string,string,string}> $rows */
    private function fixtureZip(array $rows, string $feedVersion = 'fixture-v1', bool $withStops = true): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gtfs_').'.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('feed_infos.txt', "feed_publisher_name,feed_publisher_url,feed_lang,feed_start_date,feed_end_date,feed_version\nFil Bleu (Tours),https://www.filbleu.fr,fr,20260911,20261231,$feedVersion\n");
        if ($withStops) {
            $csv = "stop_id,stop_name,stop_lat,stop_lon,location_type,parent_station,stop_timezone\n";
            foreach ($rows as $row) {
                $csv .= implode(',', array_map(static fn (string $v): string => '"'.str_replace('"', '""', $v).'"', $row)).",Europe/Paris\n";
            }
            $zip->addFromString('stops.txt', $csv);
        }
        $stopId = $rows[0][0] ?? 'station-a';
        $zip->addFromString('routes.txt', "route_id,agency_id,route_short_name,route_long_name,route_type\nroute,TTR,1,Fixture,3\n");
        $zip->addFromString('calendar.txt', "service_id,monday,tuesday,wednesday,thursday,friday,saturday,sunday,start_date,end_date\nservice,1,1,1,1,1,1,1,20260911,20261231\n");
        $zip->addFromString('calendar_dates.txt', "service_id,date,exception_type\nservice,20260923,1\n");
        $zip->addFromString('trips.txt', "route_id,service_id,trip_id,trip_headsign\nroute,service,trip,Fixture\n");
        $zip->addFromString('stop_times.txt', "trip_id,arrival_time,departure_time,stop_id,stop_sequence,pickup_type,drop_off_type\ntrip,06:00:00,06:00:00,$stopId,1,0,0\ntrip,06:01:00,06:01:00,$stopId,2,0,0\n");
        $zip->close();
        return $path;
    }
}
