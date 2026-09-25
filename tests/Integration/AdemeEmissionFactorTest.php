<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\DemoEmissionFactorRepository;
use App\Environmental\AdemeEmissionFactorImporter;
use App\Environmental\EmissionEstimator;
use App\Environmental\EmissionFactorRepositoryFactory;
use App\Environmental\PostgresEmissionFactorRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AdemeEmissionFactorTest extends KernelTestCase
{
    private Connection $db;
    private string $schema;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get('doctrine')->getConnection();
        $this->schema = 'test_ademe_'.bin2hex(random_bytes(6));
        $this->db->executeStatement('CREATE SCHEMA '.$this->schema);
        $this->db->executeStatement('SET search_path TO '.$this->schema);
        $files = glob(dirname(__DIR__, 2).'/migrations/Version*.php');
        sort($files);
        foreach ($files as $file) {
            $class = 'DoctrineMigrations\\'.basename($file, '.php');
            $migration = new $class($this->db, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $this->db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->executeStatement('SET search_path TO public');
            $this->db->executeStatement('DROP SCHEMA '.$this->schema.' CASCADE');
        }
        parent::tearDown();
    }

    public function testPinnedImportIsIdempotentAndExposesQualifiedLifeCycleProvenance(): void
    {
        $sha = $this->fixtureSha();
        $importer = $this->importer($sha);
        $first = $importer->import($this->fixture(), 'V23.6', $sha);
        $second = $importer->import($this->fixture(), 'V23.6', $sha);

        self::assertSame($first->importId, $second->importId);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM emission_factor_import'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM emission_factor'));

        $repository = new PostgresEmissionFactorRepository($this->db, $sha);
        self::assertTrue($repository->isInitialized());
        $factors = $repository->findCandidates('public_transport', 'bus_urban', 'FR-TM', new \DateTimeImmutable('2027-01-01'));
        self::assertCount(1, $factors);
        self::assertSame(0.151, $factors[0]['value']);
        self::assertSame('0,151', $factors[0]['sourceValue']);
        self::assertSame('kgCO2e/passager.km', $factors[0]['sourceUnit']);
        self::assertSame('28000', $factors[0]['externalId']);
        self::assertSame('V23.6', $factors[0]['version']);
        self::assertSame('avr-22', $factors[0]['sourcePeriod']);
        self::assertSame($sha, $factors[0]['checksumSha256']);
        self::assertSame('UTP - Enquête TCU 2017', $factors[0]['upstreamSource']);
        self::assertSame('life_cycle', $factors[0]['scope']);
        self::assertSame('ademe-v23.6-explicit-map-v2', $factors[0]['mappingMethod']);
        self::assertStringContainsString('Carburant (amont/combustion)=0,129', $factors[0]['mappingNotes']);
        self::assertStringContainsString('Fabrication=0,0225', $factors[0]['mappingNotes']);
        self::assertArrayNotHasKey('selectionStatus', $factors[0]);
        $contract = json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/openapi.yaml'), true, 512, JSON_THROW_ON_ERROR);
        $apiKeys = array_keys($factors[0]);
        $contractKeys = array_keys($contract['components']['schemas']['Factor']['properties']);
        sort($apiKeys);
        sort($contractKeys);
        self::assertSame($contractKeys, $apiKeys, 'The real ADEME Factor payload must exactly match OpenAPI (additionalProperties=false).');
        $requiredKeys = $contract['components']['schemas']['Factor']['required'];
        sort($requiredKeys);
        self::assertSame($contractKeys, $requiredKeys);
        $this->db->executeStatement("UPDATE data_source SET publisher='mutated', url='https://invalid.example', license='mutated', version='mutated'");
        $authoritative = $repository->findCandidates('public_transport', 'bus_urban', 'FR-TM', new \DateTimeImmutable('2027-01-01'))[0];
        self::assertSame(AdemeEmissionFactorImporter::CATALOG_URL, $authoritative['sourceUrl']);
        self::assertSame(AdemeEmissionFactorImporter::LICENSE, $authoritative['sourceLicense']);
        self::assertSame('V23.6', $authoritative['version']);
        self::assertSame([], $repository->findCandidates('public_transport', null, 'FR-TM', new \DateTimeImmutable('2027-01-01')));
        self::assertSame([], $repository->findCandidates('train', 'bus_urban', 'FR-TM', new \DateTimeImmutable('2027-01-01')));
    }

    public function testOnlyOfficialV236MappingAndQualifiedChecksumsAreAccepted(): void
    {
        $sha = $this->fixtureSha();
        foreach (['V23.7', 'V24', '23.6', ''] as $version) {
            try {
                $this->importer($sha)->import($this->fixture(), $version, $sha);
                self::fail($version.' must be rejected.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('version', strtolower($error->getMessage()));
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        try {
            (new AdemeEmissionFactorImporter($this->db))->import($this->fixture(), 'V23.6', $sha);
        } finally {
            self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM emission_factor_import'));
        }
    }

    public function testEffectiveDateIsStrictAndPinnedToQualifiedMapping(): void
    {
        $sha = $this->fixtureSha();
        foreach (['tomorrow', '2026-06', '2026-6-30', '2026-02-30', '2026-07-01'] as $date) {
            try {
                $this->importer($sha)->import($this->fixture(), 'V23.6', $sha, $date);
                self::fail($date.' must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM emission_factor_import'));
            }
        }
    }

    public function testPosteProofMustBeCompleteExactAndNonContradictory(): void
    {
        $original = file_get_contents($this->fixture());
        $lines = explode("\n", rtrim($original, "\r\n"));
        $cases = [
            'missing' => implode("\n", [$lines[0], $lines[1], $lines[2]])."\n",
            'modified' => $this->sourceReplace($original, '0,0225', '0,9999'),
            'contradictory duplicate' => $original.$this->sourceReplace($lines[2], '0,129', '0,999')."\n",
        ];
        foreach ($cases as $case => $content) {
            $file = $this->temporary($content);
            $sha = hash_file('sha256', $file);
            try {
                $this->importer($sha)->import($file, 'V23.6', $sha);
                self::fail($case.' Poste proof must fail closed.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('Poste', $error->getMessage());
                self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM emission_factor'));
            }
        }
    }

    public function testPeriodAndChronologyMustExactlyMatchQualifiedPublication(): void
    {
        $original = file_get_contents($this->fixture());
        $cases = [
            'period' => $this->sourceReplace($original, 'avr-22', '2022'),
            'chronology' => str_replace('27/04/2020', '27/04/2022', $original),
        ];
        foreach ($cases as $case => $content) {
            $file = $this->temporary($content);
            $sha = hash_file('sha256', $file);
            try {
                $this->importer($sha)->import($file, 'V23.6', $sha);
                self::fail($case.' must fail.');
            } catch (\InvalidArgumentException) {
                self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM emission_factor_import'));
            }
        }
    }

    public function testProviderFactorySelectsDemoAndOnlyIntegrityCheckedAdeme(): void
    {
        $demo = new DemoEmissionFactorRepository();
        $sha = $this->fixtureSha();
        $ademe = new PostgresEmissionFactorRepository($this->db, $sha);
        self::assertSame($demo, (new EmissionFactorRepositoryFactory($demo, $ademe, 'demo'))->create());
        $this->importer($sha)->import($this->fixture(), 'V23.6', $sha);
        self::assertSame($ademe, (new EmissionFactorRepositoryFactory($demo, $ademe, 'ademe'))->create());
    }

    public function testAdemeAbsentOrCorruptFailsWithoutDemoFallback(): void
    {
        $sha = $this->fixtureSha();
        $repository = new PostgresEmissionFactorRepository($this->db, $sha);
        self::assertFalse($repository->isInitialized());
        $this->assertProviderRejected($repository);

        $this->importer($sha)->import($this->fixture(), 'V23.6', $sha);
        foreach ([
            "UPDATE emission_factor SET selection_status='archived'",
            "UPDATE emission_factor SET external_id='wrong'",
            "UPDATE emission_factor SET source_license='corrupt'",
            "UPDATE emission_factor_import SET factor_count=2",
        ] as $corruption) {
            $this->db->executeStatement($corruption);
            self::assertFalse($repository->isInitialized(), $corruption);
            $this->assertProviderRejected($repository);
            $this->db->executeStatement("UPDATE emission_factor SET selection_status='active', external_id='28000', source_license=:license", ['license'=>AdemeEmissionFactorImporter::LICENSE]);
            $this->db->executeStatement('UPDATE emission_factor_import SET factor_count=1');
        }
    }

    public function testInitializationDoesNotMaskDatabaseFailure(): void
    {
        $repository = new PostgresEmissionFactorRepository($this->db, $this->fixtureSha());
        $this->db->executeStatement('DROP TABLE emission_factor_import CASCADE');
        $this->expectException(DbalException::class);
        $repository->isInitialized();
    }

    public function testChecksumCollisionIsRejectedAndHistoryIsStructurallyImmutable(): void
    {
        $sha = $this->fixtureSha();
        $alternate = $this->temporary(file_get_contents($this->fixture())."\n");
        $alternateSha = hash_file('sha256', $alternate);
        $importer = new AdemeEmissionFactorImporter($this->db, [$sha, $alternateSha]);
        $importer->import($this->fixture(), 'V23.6', $sha);

        try {
            $importer->import($alternate, 'V23.6', $alternateSha);
            self::fail('A different checksum for the same publication must collide.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('collision', $error->getMessage());
        }
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM emission_factor_import'));
        self::assertSame($sha, $this->db->fetchOne('SELECT checksum_sha256 FROM emission_factor_import'));
        self::assertSame('ADEME', $this->db->fetchOne('SELECT publisher FROM emission_factor_import'));
    }

    public function testFailureAfterMetadataWritesRollsBackWholeTransaction(): void
    {
        $this->db->executeStatement("CREATE FUNCTION reject_factor() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'forced factor failure'; END $$");
        $this->db->executeStatement('CREATE TRIGGER reject_factor BEFORE INSERT ON emission_factor FOR EACH ROW EXECUTE FUNCTION reject_factor()');
        $sha = $this->fixtureSha();
        try {
            $this->importer($sha)->import($this->fixture(), 'V23.6', $sha);
            self::fail('Forced write failure must propagate.');
        } catch (DbalException) {
            self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM data_source'));
            self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM emission_factor_import'));
            self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM emission_factor'));
        }
    }

    public function testArchivedOutOfPeriodAndAmbiguousFactorsAreUnavailable(): void
    {
        $sha = $this->fixtureSha();
        $this->importer($sha)->import($this->fixture(), 'V23.6', $sha);
        $repository = new PostgresEmissionFactorRepository($this->db, $sha);
        self::assertSame([], $repository->findCandidates('public_transport', 'bus_urban', 'FR-TM', new \DateTimeImmutable('2026-06-29')));
        $this->db->executeStatement("UPDATE emission_factor SET selection_status='archived'");
        self::assertSame([], $repository->findCandidates('public_transport', 'bus_urban', 'FR-TM', new \DateTimeImmutable('2027-01-01')));
        $this->db->executeStatement("UPDATE emission_factor SET selection_status='active'");
        $row = $this->db->fetchAssociative('SELECT * FROM emission_factor');
        unset($row['id']);
        $row['id'] = 'ambiguous-factor';
        $row['external_id'] = '28000-alternate';
        $this->db->insert('emission_factor', $row);
        $estimate = (new EmissionEstimator($repository))->estimate([
            ['id'=>'leg','mode'=>'public_transport','subtype'=>'bus_urban','distanceKm'=>10.0],
        ], 1, 'FR-TM', new \DateTimeImmutable('2027-01-01'), 'real');
        self::assertSame('unavailable', $estimate['status']);
        self::assertNull($estimate['kgCO2ePerTraveler']);
        self::assertStringContainsString('Plusieurs facteurs', $estimate['legs'][0]['reason']);
    }

    public function testInvalidStructureStatusUnitValueMappingDateAndEncodingAreFailClosed(): void
    {
        $original = file_get_contents($this->fixture());
        $invalid = [
            'separator' => str_replace(';', ',', $original),
            'columns' => preg_replace('/;[^;]+/', '', $original, 1),
            'negative value' => str_replace('0,151', '-0,151', $original),
            'non numeric value' => str_replace('0,151', 'abc', $original),
            'unit' => str_replace('kgCO2e/passager.km', 'gCO2e/passager.km', $original),
            'status' => $this->sourceReplace($original, 'Valide générique', 'Archivé'),
            'geography mapping' => str_replace('France continentale', 'Monde', $original),
            'scope mapping' => $this->sourceReplace($original, 'élément décomposé par poste', 'élément non décomposé'),
            'date' => str_replace('15/12/2021', '31/02/2021', $original),
            'utf8 instead of source encoding' => mb_convert_encoding($original, 'UTF-8', 'Windows-1252'),
        ];
        foreach ($invalid as $case => $content) {
            try {
                $file = $this->temporary((string)$content);
                $sha = hash_file('sha256', $file);
                $this->importer($sha)->import($file, 'V23.6', $sha);
                self::fail($case.' must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertSame(0, (int)$this->db->fetchOne('SELECT count(*) FROM emission_factor_import'), $case);
            }
        }
    }

    private function assertProviderRejected(PostgresEmissionFactorRepository $repository): void
    {
        try {
            (new EmissionFactorRepositoryFactory(new DemoEmissionFactorRepository(), $repository, 'ademe'))->create();
            self::fail('Corrupt or absent ADEME import must not fall back.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('no demo fallback', $error->getMessage());
        }
    }

    private function importer(string $qualifiedSha): AdemeEmissionFactorImporter
    {
        return new AdemeEmissionFactorImporter($this->db, [$qualifiedSha]);
    }

    private function fixture(): string
    {
        return dirname(__DIR__).'/Fixtures/ademe-base-carbone-v23.6/selected.csv';
    }

    private function fixtureSha(): string
    {
        return hash_file('sha256', $this->fixture());
    }

    private function temporary(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ademe-');
        file_put_contents($path, $content);
        return $path;
    }

    private function sourceReplace(string $content, string $search, string $replacement): string
    {
        $utf8 = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        return mb_convert_encoding(str_replace($search, $replacement, $utf8), 'Windows-1252', 'UTF-8');
    }
}
