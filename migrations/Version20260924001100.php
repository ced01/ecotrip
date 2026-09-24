<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924001100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add immutable ADEME emission-factor import history and full source provenance';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE emission_factor_import (
 id BIGSERIAL PRIMARY KEY,
 source_id VARCHAR(64) NOT NULL REFERENCES data_source(id),
 source_version VARCHAR(100) NOT NULL,
 checksum_sha256 CHAR(64) NOT NULL CHECK (checksum_sha256 ~ '^[0-9a-f]{64}$'),
 accessed_at DATE NOT NULL,
 effective_from DATE NOT NULL,
 imported_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 factor_count INT NOT NULL CHECK (factor_count > 0),
 status VARCHAR(20) NOT NULL CHECK (status IN ('complete')),
 UNIQUE(source_id, source_version, checksum_sha256),
 UNIQUE(source_id, source_version)
)
SQL);
        $this->addSql(<<<'SQL'
ALTER TABLE emission_factor
 ADD COLUMN import_id BIGINT REFERENCES emission_factor_import(id),
 ADD COLUMN external_id VARCHAR(100),
 ADD COLUMN source_value VARCHAR(100),
 ADD COLUMN source_unit VARCHAR(100),
 ADD COLUMN source_status VARCHAR(100),
 ADD COLUMN source_name TEXT,
 ADD COLUMN source_attribute TEXT,
 ADD COLUMN source_geography TEXT,
 ADD COLUMN source_period TEXT,
 ADD COLUMN upstream_source TEXT,
 ADD COLUMN source_url TEXT,
 ADD COLUMN source_license TEXT,
 ADD COLUMN accessed_at DATE,
 ADD COLUMN checksum_sha256 CHAR(64),
 ADD COLUMN selection_status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (selection_status IN ('active','archived','expired','incompatible')),
 ADD COLUMN mapping_method VARCHAR(100),
 ADD COLUMN mapping_notes TEXT
SQL);
        $this->addSql('CREATE UNIQUE INDEX factor_external_version_uidx ON emission_factor(source_id, external_id, version) WHERE external_id IS NOT NULL');
        $this->addSql('CREATE INDEX factor_deterministic_lookup_idx ON emission_factor(mode, subtype, geography, valid_from, valid_until, status, version)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX factor_external_version_uidx');
        $this->addSql('DROP INDEX factor_deterministic_lookup_idx');
        $this->addSql('ALTER TABLE emission_factor DROP COLUMN import_id, DROP COLUMN external_id, DROP COLUMN source_value, DROP COLUMN source_unit, DROP COLUMN source_status, DROP COLUMN source_name, DROP COLUMN source_attribute, DROP COLUMN source_geography, DROP COLUMN source_period, DROP COLUMN upstream_source, DROP COLUMN source_url, DROP COLUMN source_license, DROP COLUMN accessed_at, DROP COLUMN checksum_sha256, DROP COLUMN selection_status, DROP COLUMN mapping_method, DROP COLUMN mapping_notes');
        $this->addSql('DROP TABLE emission_factor_import');
    }
}
