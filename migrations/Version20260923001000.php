<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923001000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add versioned GTFS import journal and durable external place references';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE place_import (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    source_id VARCHAR(64) NOT NULL REFERENCES data_source(id),
    provider_key VARCHAR(64) NOT NULL,
    feed_version VARCHAR(100) NOT NULL,
    checksum_sha256 CHAR(64) NOT NULL CHECK (checksum_sha256 ~ '^[0-9a-f]{64}$'),
    imported_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    station_count INT NOT NULL CHECK (station_count >= 0),
    reference_count INT NOT NULL CHECK (reference_count >= station_count)
)
SQL);
        $this->addSql('CREATE INDEX place_import_source_idx ON place_import(source_id, imported_at DESC)');
        $this->addSql(<<<'SQL'
CREATE TABLE place_external_reference (
    provider_key VARCHAR(64) NOT NULL,
    external_id VARCHAR(255) NOT NULL,
    place_id VARCHAR(64) REFERENCES place(id),
    parent_external_id VARCHAR(255),
    location_type SMALLINT NOT NULL CHECK (location_type IN (0, 1)),
    active BOOLEAN NOT NULL DEFAULT TRUE,
    first_seen_import_id BIGINT NOT NULL REFERENCES place_import(id),
    last_seen_import_id BIGINT NOT NULL REFERENCES place_import(id),
    PRIMARY KEY (provider_key, external_id),
    CHECK ((location_type = 1 AND place_id IS NOT NULL) OR (location_type = 0 AND place_id IS NULL))
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX place_external_active_place_idx ON place_external_reference(provider_key, place_id) WHERE place_id IS NOT NULL');
        $this->addSql('CREATE INDEX place_external_parent_idx ON place_external_reference(provider_key, parent_external_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE place_external_reference');
        $this->addSql('DROP TABLE place_import');
    }
}
