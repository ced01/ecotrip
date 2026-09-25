<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\DemoEmissionFactorRepository;
use App\Environmental\AdemePublicationRegistry;
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

    public function testAdemeAbsentOrEveryCorruptInvariantFailsWithoutDemoFallback(): void
    {
        $sha = $this->fixtureSha();
        $repository = new PostgresEmissionFactorRepository($this->db, $sha);
        self::assertFalse($repository->isInitialized());
        $this->assertProviderRejected($repository);

        $this->importer($sha)->import($this->fixture(), 'V23.6', $sha);
        $this->db->executeStatement(<<<'SQL'
INSERT INTO data_source (id,publisher,url,license,accessed_at,version,reuse_notes,data_status)
VALUES ('unqualified-source','Synthetic','https://invalid.example','none','2026-09-24','TEST','Test-only FK target.','demo')
SQL);
        $factor = $this->db->fetchAssociative('SELECT * FROM emission_factor');
        $import = $this->db->fetchAssociative('SELECT * FROM emission_factor_import');
        self::assertIsArray($factor);
        self::assertIsArray($import);
        $corruptions = [
            ['emission_factor', 'value', '0.152'],
            ['emission_factor', 'source_value', '0,152'],
            ['emission_factor', 'valid_from', '2026-07-01'],
            ['emission_factor', 'valid_until', '2027-01-01'],
            ['emission_factor', 'source_status', 'Archivé'],
            ['emission_factor', 'source_name', 'Autobus inventé'],
            ['emission_factor', 'source_attribute', 'Autre agglomération'],
            ['emission_factor', 'source_geography', 'Monde'],
            ['emission_factor', 'source_period', '2022'],
            ['emission_factor', 'upstream_source', 'Source inconnue'],
            ['emission_factor', 'source_id', 'unqualified-source'],
            ['emission_factor', 'external_id', 'wrong'],
            ['emission_factor', 'mode', 'train'],
            ['emission_factor', 'subtype', 'coach'],
            ['emission_factor', 'geography', 'FR'],
            ['emission_factor', 'scope', 'operation'],
            ['emission_factor', 'unit', 'kgCO2e/vehicle-km'],
            ['emission_factor', 'status', 'synthetic_test'],
            ['emission_factor', 'selection_status', 'archived'],
            ['emission_factor', 'checksum_sha256', str_repeat('a', 64)],
            ['emission_factor', 'version', 'V23.7'],
            ['emission_factor', 'source_url', 'https://invalid.example'],
            ['emission_factor', 'source_license', 'corrupt'],
            ['emission_factor', 'accessed_at', '2026-09-25'],
            ['emission_factor', 'mapping_method', 'unreviewed'],
            ['emission_factor', 'mapping_notes', 'insufficient proof'],
            ['emission_factor_import', 'source_id', 'unqualified-source'],
            ['emission_factor_import', 'source_version', 'V23.7'],
            ['emission_factor_import', 'checksum_sha256', str_repeat('b', 64)],
            ['emission_factor_import', 'accessed_at', '2026-09-25'],
            ['emission_factor_import', 'effective_from', '2026-07-01'],
            ['emission_factor_import', 'factor_count', 2],
            ['emission_factor_import', 'publisher', 'Not ADEME'],
            ['emission_factor_import', 'publication_url', 'https://invalid.example'],
            ['emission_factor_import', 'source_license', 'corrupt'],
            ['emission_factor_import', 'mapping_method', 'unreviewed'],
        ];
        foreach ($corruptions as [$table, $field, $badValue]) {
            $id = $table === 'emission_factor' ? $factor['id'] : $import['id'];
            $original = $table === 'emission_factor' ? $factor[$field] : $import[$field];
            $this->db->update($table, [$field => $badValue], ['id' => $id]);
            $label = $table.'.'.$field;
            self::assertFalse($repository->isInitialized(), $label);
            $this->assertProviderRejected($repository);
            $this->db->update($table, [$field => $original], ['id' => $id]);
            self::assertTrue($repository->isInitialized(), 'restored '.$label);
        }
    }

    public function testUnqualifiedPublicationsAreNeitherSelectedNorAllowedToMaskTheQualifiedCandidate(): void
    {
        $sha = $this->fixtureSha();
        $this->importer($sha)->import($this->fixture(), 'V23.6', $sha);
        $repository = new PostgresEmissionFactorRepository($this->db, $sha);

        foreach ([
            'other-source' => ['source_id' => 'unqualified-source'],
            'wrong-version' => ['source_version' => 'V23.7'],
            'wrong-checksum' => ['checksum_sha256' => str_repeat('c', 64)],
            'wrong-method' => ['mapping_method' => 'unreviewed'],
            'wrong-external-id' => ['external_id' => '99999'],
            'wrong-publisher' => ['publisher' => 'Not ADEME'],
        ] as $name => $changes) {
            $this->insertSyntheticPublication('bad-'.$name, '2099-01-01', $changes);
        }

        $candidates = $repository->findCandidates('public_transport', 'bus_urban', 'FR-TM', new \DateTimeImmutable('2100-01-01'));
        self::assertCount(1, $candidates);
        self::assertSame('V23.6', $candidates[0]['version']);
        self::assertSame('28000', $candidates[0]['externalId']);

        $this->db->executeStatement("UPDATE emission_factor SET source_period='corrupt' WHERE version='V23.6'");
        self::assertSame([], $repository->findCandidates('public_transport', 'bus_urban', 'FR-TM', new \DateTimeImmutable('2100-01-01')));
    }

    public function testSyntheticQualifiedRegistryPreservesHistoryAndSelectsDeterministicallyByDate(): void
    {
        $oldChecksum = str_repeat('d', 64);
        $newChecksum = str_repeat('e', 64);
        $registry = AdemePublicationRegistry::syntheticForRepositoryTest([
            ['sourceVersion' => 'SYNTHETIC-OLD', 'checksum' => $oldChecksum, 'effectiveFrom' => '2025-01-01'],
            ['sourceVersion' => 'SYNTHETIC-NEW', 'checksum' => $newChecksum, 'effectiveFrom' => '2026-01-01'],
        ]);
        $this->insertSyntheticPublication('SYNTHETIC-OLD', '2025-01-01', ['checksum_sha256' => $oldChecksum]);
        $this->insertSyntheticPublication('SYNTHETIC-NEW', '2026-01-01', ['checksum_sha256' => $newChecksum]);

        self::assertSame(2, (int) $this->db->fetchOne('SELECT count(*) FROM emission_factor_import'));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT count(*) FROM emission_factor'));

        $production = new PostgresEmissionFactorRepository($this->db, $this->fixtureSha());
        self::assertFalse($production->isInitialized(), 'Synthetic publications must never activate the production provider.');
        self::assertSame([], $production->findCandidates('public_transport', 'bus_urban', 'FR-TM', new \DateTimeImmutable('2027-01-01')));

        $structuralRepository = new PostgresEmissionFactorRepository($this->db, null, $registry);
        self::assertSame('SYNTHETIC-OLD', $structuralRepository->findCandidates('public_transport', 'bus_urban', 'FR-TM', new \DateTimeImmutable('2025-06-01'))[0]['version']);
        self::assertSame('SYNTHETIC-NEW', $structuralRepository->findCandidates('public_transport', 'bus_urban', 'FR-TM', new \DateTimeImmutable('2026-06-01'))[0]['version']);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT count(*) FROM emission_factor'), 'Selecting a newer publication must not mutate history.');
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

    public function testArchivedOutOfPeriodAndAmbiguousOrCorruptFactorsAreUnavailable(): void
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
        self::assertStringContainsString('Aucun facteur', $estimate['legs'][0]['reason']);
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

    /** @param array<string, string> $changes */
    private function insertSyntheticPublication(string $version, string $effectiveFrom, array $changes = []): void
    {
        $sourceId = $changes['source_id'] ?? AdemeEmissionFactorImporter::SOURCE_ID;
        $sourceVersion = $changes['source_version'] ?? $version;
        $checksum = $changes['checksum_sha256'] ?? hash('sha256', $version);
        $mappingMethod = $changes['mapping_method'] ?? AdemeEmissionFactorImporter::MAPPING_METHOD;
        $publisher = $changes['publisher'] ?? 'ADEME';
        $externalId = $changes['external_id'] ?? '28000';

        $this->db->executeStatement(<<<'SQL'
INSERT INTO data_source (id,publisher,url,license,accessed_at,version,reuse_notes,data_status)
VALUES (:id,'ADEME',:url,:license,:accessed,:version,'Explicitly synthetic integration-test data; never production-qualified.','verified')
ON CONFLICT (id) DO NOTHING
SQL, ['id' => $sourceId, 'url' => AdemeEmissionFactorImporter::CATALOG_URL, 'license' => AdemeEmissionFactorImporter::LICENSE, 'accessed' => AdemeEmissionFactorImporter::ACCESSED_AT, 'version' => $sourceVersion]);
        $importId = (int) $this->db->fetchOne(<<<'SQL'
INSERT INTO emission_factor_import
(source_id,source_version,checksum_sha256,accessed_at,effective_from,factor_count,status,publisher,publication_url,source_license,mapping_method)
VALUES (:source,:version,:checksum,:accessed,:effective,1,'complete',:publisher,:url,:license,:mapping)
RETURNING id
SQL, ['source' => $sourceId, 'version' => $sourceVersion, 'checksum' => $checksum, 'accessed' => AdemeEmissionFactorImporter::ACCESSED_AT, 'effective' => $effectiveFrom, 'publisher' => $publisher, 'url' => AdemeEmissionFactorImporter::CATALOG_URL, 'license' => AdemeEmissionFactorImporter::LICENSE, 'mapping' => $mappingMethod]);

        $factor = $this->db->fetchAssociative("SELECT * FROM emission_factor WHERE version='V23.6' LIMIT 1");
        if ($factor === false) {
            $factor = $this->qualifiedFactorRow();
        }
        $factor['id'] = 'synthetic-'.substr(hash('sha256', $sourceId."\0".$sourceVersion), 0, 32);
        $factor['import_id'] = $importId;
        $factor['source_id'] = $sourceId;
        $factor['version'] = $sourceVersion;
        $factor['external_id'] = $externalId;
        $factor['valid_from'] = $effectiveFrom;
        $factor['valid_until'] = null;
        $factor['checksum_sha256'] = $checksum;
        $factor['mapping_method'] = $mappingMethod;
        $this->db->insert('emission_factor', $factor);
    }

    /** @return array<string, mixed> */
    private function qualifiedFactorRow(): array
    {
        return [
            'value' => '0.151', 'unit' => 'kgCO2e/passenger-km', 'mode' => 'public_transport', 'subtype' => 'bus_urban',
            'geography' => 'FR-TM', 'valid_from' => AdemeEmissionFactorImporter::EFFECTIVE_FROM, 'valid_until' => null,
            'scope' => 'life_cycle', 'occupancy' => null, 'status' => 'verified', 'source_value' => '0,151',
            'source_unit' => 'kgCO2e/passager.km', 'source_status' => 'Valide générique', 'source_name' => 'Autobus moyen',
            'source_attribute' => 'Agglomération de plus de 250 000 habitants', 'source_geography' => 'France continentale',
            'source_period' => 'avr-22', 'upstream_source' => 'UTP - Enquête TCU 2017',
            'source_url' => AdemeEmissionFactorImporter::CATALOG_URL, 'source_license' => AdemeEmissionFactorImporter::LICENSE,
            'accessed_at' => AdemeEmissionFactorImporter::ACCESSED_AT, 'selection_status' => 'active',
            'mapping_notes' => AdemeEmissionFactorImporter::MAPPING_NOTES,
        ];
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
