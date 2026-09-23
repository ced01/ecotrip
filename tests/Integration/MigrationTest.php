<?php
namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MigrationTest extends KernelTestCase
{
    public function testFoundationMigrationOnEmptyPostgresSchemaIsReversible(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get('doctrine')->getConnection();
        self::assertInstanceOf(Connection::class, $connection);
        $schemaName = 'test_foundation_'.bin2hex(random_bytes(6));
        $connection->executeStatement('CREATE SCHEMA '.$schemaName);
        $connection->executeStatement('SET search_path TO '.$schemaName);
        try {
            self::assertSame([], $this->listCurrentSchemaTableNames($connection));
            $files = glob(dirname(__DIR__, 2).'/migrations/Version*.php');
            self::assertNotEmpty($files, 'At least one migration is required.');
            sort($files);
            foreach ($files as $file) {
                $class = 'DoctrineMigrations\\'.basename($file, '.php');
                $up = new $class($connection, new NullLogger());
                $up->up(new Schema());
                foreach ($up->getSql() as $query) {
                    $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
                }
            }
            $tables = $this->listCurrentSchemaTableNames($connection);
            self::assertSame(['accommodation', 'data_source', 'emission_factor', 'environmental_evidence', 'journey_leg', 'journey_scenario', 'place', 'place_external_reference', 'place_import'], $tables);
            foreach ($tables as $table) {
                self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM '.$table));
            }
            foreach (array_reverse($files) as $file) {
                $class = 'DoctrineMigrations\\'.basename($file, '.php');
                $down = new $class($connection, new NullLogger());
                $down->down(new Schema());
                foreach ($down->getSql() as $query) {
                    $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
                }
            }
            self::assertSame([], $this->listCurrentSchemaTableNames($connection));
        } finally {
            $connection->executeStatement('SET search_path TO public');
            // Only this test's random schema, never an application table or volume.
            $connection->executeStatement('DROP SCHEMA '.$schemaName.' CASCADE');
        }
    }

    /** @return list<string> */
    private function listCurrentSchemaTableNames(Connection $connection): array
    {
        return $connection->fetchFirstColumn(<<<'SQL'
            SELECT table_name
            FROM information_schema.tables
            WHERE table_catalog = current_database()
              AND table_schema = current_schema()
              AND table_type = 'BASE TABLE'
            ORDER BY table_name
            SQL);
    }
}
