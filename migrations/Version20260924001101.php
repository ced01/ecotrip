<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924001101 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make each immutable emission-factor import authoritative for publication provenance';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE emission_factor_import ADD COLUMN publisher VARCHAR(100), ADD COLUMN publication_url TEXT, ADD COLUMN source_license TEXT, ADD COLUMN mapping_method VARCHAR(100)");
        $this->addSql(<<<'SQL'
UPDATE emission_factor_import i SET
 publisher = COALESCE((SELECT d.publisher FROM data_source d WHERE d.id=i.source_id), 'ADEME'),
 publication_url = COALESCE((SELECT f.source_url FROM emission_factor f WHERE f.import_id=i.id LIMIT 1), (SELECT d.url FROM data_source d WHERE d.id=i.source_id)),
 source_license = COALESCE((SELECT f.source_license FROM emission_factor f WHERE f.import_id=i.id LIMIT 1), (SELECT d.license FROM data_source d WHERE d.id=i.source_id)),
 mapping_method = COALESCE((SELECT f.mapping_method FROM emission_factor f WHERE f.import_id=i.id LIMIT 1), 'legacy-unqualified')
SQL);
        $this->addSql('ALTER TABLE emission_factor_import ALTER COLUMN publisher SET NOT NULL, ALTER COLUMN publication_url SET NOT NULL, ALTER COLUMN source_license SET NOT NULL, ALTER COLUMN mapping_method SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE emission_factor_import DROP COLUMN publisher, DROP COLUMN publication_url, DROP COLUMN source_license, DROP COLUMN mapping_method');
    }
}
