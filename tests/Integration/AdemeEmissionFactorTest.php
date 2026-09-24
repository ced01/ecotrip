<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Demo\DemoEmissionFactorRepository;
use App\Environmental\AdemeEmissionFactorImporter;
use App\Environmental\EmissionFactorRepositoryFactory;
use App\Environmental\PostgresEmissionFactorRepository;
use Doctrine\DBAL\Connection;
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

    public function testPinnedImportIsIdempotentAndExposesCompleteProvenance(): void
    {
        $file = $this->fixture();
        $sha = hash_file('sha256', $file);
        $importer = new AdemeEmissionFactorImporter($this->db);

        $first = $importer->import($file, 'V23.6', $sha);
        $second = $importer->import($file, 'V23.6', $sha);

        self::assertSame($first->importId, $second->importId);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM emission_factor_import'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM emission_factor'));

        $factors = (new PostgresEmissionFactorRepository($this->db))->findCandidates('public_transport', null, 'FR-TM', new \DateTimeImmutable('2027-01-01'));
        self::assertCount(1, $factors);
        self::assertSame(0.151, $factors[0]['value']);
        self::assertSame('0,151', $factors[0]['sourceValue']);
        self::assertSame('kgCO2e/passager.km', $factors[0]['sourceUnit']);
        self::assertSame('28000', $factors[0]['externalId']);
        self::assertSame('V23.6', $factors[0]['version']);
        self::assertSame($sha, $factors[0]['checksumSha256']);
        self::assertSame('UTP - Enquête TCU 2017', $factors[0]['upstreamSource']);
        self::assertSame('life_cycle', $factors[0]['scope']);
        self::assertSame('ademe-v23.6-explicit-map-v1', $factors[0]['mappingMethod']);
        self::assertStringContainsString('301 339 habitants', $factors[0]['mappingNotes']);
        self::assertStringContainsString('https://www.insee.fr/fr/statistiques/1405599?geo=EPCI-243700754', $factors[0]['mappingNotes']);
    }

    public function testProviderFactorySelectsDemoAndAdemeExplicitly(): void
    {
        $demo = new DemoEmissionFactorRepository();
        $ademe = new PostgresEmissionFactorRepository($this->db);

        self::assertSame($demo, (new EmissionFactorRepositoryFactory($demo, $ademe, 'demo'))->create());

        $file = $this->fixture();
        (new AdemeEmissionFactorImporter($this->db))->import($file, 'V23.6', hash_file('sha256', $file));
        self::assertSame($ademe, (new EmissionFactorRepositoryFactory($demo, $ademe, 'ademe'))->create());
    }

    public function testUnknownProviderIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expected demo or ademe');

        (new EmissionFactorRepositoryFactory(
            new DemoEmissionFactorRepository(),
            new PostgresEmissionFactorRepository($this->db),
            'other',
        ))->create();
    }

    public function testAdemeWithoutCompleteImportFailsWithoutDemoFallback(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no demo fallback');

        (new EmissionFactorRepositoryFactory(
            new DemoEmissionFactorRepository(),
            new PostgresEmissionFactorRepository($this->db),
            'ademe',
        ))->create();
    }

    public function testNewPublicationPreservesHistoryAndDateSelectionIsUnambiguous(): void
    {
        $file = $this->fixture();
        $importer = new AdemeEmissionFactorImporter($this->db);
        $importer->import($file, 'V23.6', hash_file('sha256', $file));
        $next = $this->temporary(str_replace('0,151', '0,152', file_get_contents($file)));
        $importer->import($next, 'V23.7', hash_file('sha256', $next), new \DateTimeImmutable('2027-06-01'));

        self::assertSame(2, (int) $this->db->fetchOne('SELECT count(*) FROM emission_factor'));
        $repository = new PostgresEmissionFactorRepository($this->db);
        self::assertSame(0.151, $repository->findCandidates('public_transport', null, 'FR-TM', new \DateTimeImmutable('2027-01-01'))[0]['value']);
        self::assertSame(0.152, $repository->findCandidates('public_transport', null, 'FR-TM', new \DateTimeImmutable('2027-06-01'))[0]['value']);
    }

    public function testChecksumAndMissingExpectedIdentifierFailBeforeWriting(): void
    {
        $importer = new AdemeEmissionFactorImporter($this->db);
        try {
            $importer->import($this->fixture(), 'V23.6', str_repeat('0', 64));
            self::fail('Checksum mismatch must fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('checksum', strtolower($e->getMessage()));
        }
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM emission_factor_import'));

        $missing = $this->temporary(str_replace(';28000;', ';99999;', file_get_contents($this->fixture())));
        $this->expectException(\InvalidArgumentException::class);
        try {
            $importer->import($missing, 'V23.6', hash_file('sha256', $missing));
        } finally {
            self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM emission_factor_import'));
        }
    }

    public function testContradictoryDuplicateRollsBackAtomically(): void
    {
        $content = file_get_contents($this->fixture());
        $lines = explode("\n", rtrim($content, "\r\n"));
        $duplicate = $lines[1];
        $bad = $this->temporary($content.str_replace('0,151', '0,999', $duplicate)."\n");
        $this->expectException(\InvalidArgumentException::class);
        try {
            (new AdemeEmissionFactorImporter($this->db))->import($bad, 'V23.6', hash_file('sha256', $bad));
        } finally {
            self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM emission_factor'));
        }
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
        $importer = new AdemeEmissionFactorImporter($this->db);
        foreach ($invalid as $case => $content) {
            try {
                $file = $this->temporary((string)$content);
                $importer->import($file, 'V23.6', hash_file('sha256', $file));
                self::fail($case.' must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertSame(0, (int)$this->db->fetchOne('SELECT count(*) FROM emission_factor_import'), $case);
            }
        }
    }

    private function fixture(): string
    {
        return dirname(__DIR__).'/Fixtures/ademe-base-carbone-v23.6/selected.csv';
    }

    private function temporary(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ademe-');
        file_put_contents($path, $content);
        $this->addToAssertionCount(1);
        return $path;
    }

    private function sourceReplace(string $content, string $search, string $replacement): string
    {
        $utf8 = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        return mb_convert_encoding(str_replace($search, $replacement, $utf8), 'Windows-1252', 'UTF-8');
    }
}
