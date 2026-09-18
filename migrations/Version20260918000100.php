<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260918000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Foundation catalogue: sources, places, scenarios, factors, stays and evidence; no fixtures';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE data_source (
    id VARCHAR(64) PRIMARY KEY,
    publisher VARCHAR(255) NOT NULL,
    url TEXT,
    license TEXT,
    accessed_at DATE,
    version VARCHAR(100),
    reuse_notes TEXT NOT NULL,
    data_status VARCHAR(20) NOT NULL CHECK (data_status IN ('demo', 'verified', 'unverified'))
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE place (
    id VARCHAR(64) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    country_code CHAR(2) NOT NULL,
    latitude DOUBLE PRECISION CHECK (latitude BETWEEN -90 AND 90),
    longitude DOUBLE PRECISION CHECK (longitude BETWEEN -180 AND 180),
    timezone VARCHAR(100) NOT NULL,
    source_id VARCHAR(64) NOT NULL REFERENCES data_source(id),
    data_status VARCHAR(20) NOT NULL CHECK (data_status IN ('demo', 'verified', 'unverified', 'unknown'))
)
SQL);
        $this->addSql('CREATE INDEX place_source_idx ON place(source_id)');
        $this->addSql(<<<'SQL'
CREATE TABLE journey_scenario (
    id VARCHAR(64) PRIMARY KEY,
    origin_id VARCHAR(64) NOT NULL REFERENCES place(id),
    destination_id VARCHAR(64) NOT NULL REFERENCES place(id),
    name VARCHAR(255) NOT NULL,
    source_id VARCHAR(64) NOT NULL REFERENCES data_source(id),
    data_status VARCHAR(20) NOT NULL CHECK (data_status IN ('demo', 'real')),
    CHECK (origin_id <> destination_id)
)
SQL);
        $this->addSql('CREATE INDEX scenario_pair_idx ON journey_scenario(origin_id, destination_id)');
        $this->addSql(<<<'SQL'
CREATE TABLE journey_leg (
    id VARCHAR(64) PRIMARY KEY,
    scenario_id VARCHAR(64) NOT NULL REFERENCES journey_scenario(id),
    position INT NOT NULL CHECK (position >= 0),
    origin_id VARCHAR(64) NOT NULL REFERENCES place(id),
    destination_id VARCHAR(64) NOT NULL REFERENCES place(id),
    mode VARCHAR(30) NOT NULL CHECK (mode IN ('train','coach','walk','public_transport','bicycle','carpool','flight')),
    subtype VARCHAR(100),
    duration_minutes INT NOT NULL CHECK (duration_minutes >= 0),
    waiting_minutes INT NOT NULL CHECK (waiting_minutes >= 0),
    distance_km NUMERIC(12,3) CHECK (distance_km >= 0),
    distance_method VARCHAR(30) NOT NULL CHECK (distance_method IN ('scenario','routed','great_circle','unknown')),
    distance_source_id VARCHAR(64) REFERENCES data_source(id),
    schedule_source_id VARCHAR(64) REFERENCES data_source(id),
    departure_at TIMESTAMPTZ,
    arrival_at TIMESTAMPTZ,
    source_id VARCHAR(64) NOT NULL REFERENCES data_source(id),
    provenance JSONB NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(provenance) = 'object'),
    UNIQUE(scenario_id, position),
    CHECK (arrival_at IS NULL OR departure_at IS NULL OR arrival_at >= departure_at)
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE emission_factor (
    id VARCHAR(64) PRIMARY KEY,
    value NUMERIC(18,9) NOT NULL CHECK (value >= 0),
    unit VARCHAR(40) NOT NULL CHECK (unit IN ('kgCO2e/passenger-km','kgCO2e/vehicle-km')),
    mode VARCHAR(30) NOT NULL CHECK (mode IN ('train','coach','walk','public_transport','bicycle','carpool','flight')),
    subtype VARCHAR(100),
    geography VARCHAR(100) NOT NULL,
    valid_from DATE NOT NULL,
    valid_until DATE,
    scope VARCHAR(30) NOT NULL CHECK (scope IN ('operation','life_cycle')),
    occupancy NUMERIC(10,3) CHECK (occupancy > 0),
    version VARCHAR(100) NOT NULL,
    source_id VARCHAR(64) NOT NULL REFERENCES data_source(id),
    status VARCHAR(30) NOT NULL CHECK (status IN ('verified','synthetic_test')),
    CHECK (valid_until IS NULL OR valid_until >= valid_from)
)
SQL);
        $this->addSql('CREATE INDEX factor_lookup_idx ON emission_factor(mode, geography, valid_from)');
        $this->addSql(<<<'SQL'
CREATE TABLE accommodation (
    id VARCHAR(64) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    destination_id VARCHAR(64) NOT NULL REFERENCES place(id),
    bicycle_parking BOOLEAN,
    public_transport_nearby BOOLEAN,
    public_transport_distance_meters INT CHECK (public_transport_distance_meters >= 0),
    price_amount NUMERIC(12,2) CHECK (price_amount >= 0),
    price_currency CHAR(3),
    price_basis VARCHAR(30) CHECK (price_basis IN ('night_per_room','night_per_person')),
    price_as_of DATE,
    price_source_id VARCHAR(64) REFERENCES data_source(id),
    price_data_status VARCHAR(20) CHECK (price_data_status IN ('demo','verified','unverified')),
    source_id VARCHAR(64) NOT NULL REFERENCES data_source(id),
    data_status VARCHAR(20) NOT NULL CHECK (data_status IN ('demo','real')),
    CHECK ((price_amount IS NULL AND price_currency IS NULL AND price_basis IS NULL AND price_as_of IS NULL AND price_source_id IS NULL AND price_data_status IS NULL)
        OR (price_amount IS NOT NULL AND price_currency IS NOT NULL AND price_basis IS NOT NULL AND price_as_of IS NOT NULL AND price_source_id IS NOT NULL AND price_data_status IS NOT NULL))
)
SQL);
        $this->addSql('CREATE INDEX accommodation_destination_idx ON accommodation(destination_id)');
        $this->addSql(<<<'SQL'
CREATE TABLE environmental_evidence (
    id VARCHAR(64) PRIMARY KEY,
    accommodation_id VARCHAR(64) NOT NULL REFERENCES accommodation(id),
    claim TEXT NOT NULL,
    kind VARCHAR(20) NOT NULL CHECK (kind IN ('declaration','certification')),
    status VARCHAR(20) NOT NULL CHECK (status IN ('declared','unverified','verified','expired','demo')),
    organization VARCHAR(255),
    reference_url TEXT,
    valid_from DATE,
    valid_until DATE,
    checked_at DATE,
    source_id VARCHAR(64) NOT NULL REFERENCES data_source(id),
    CHECK (valid_until IS NULL OR valid_from IS NULL OR valid_until >= valid_from),
    CHECK (status <> 'verified' OR (kind = 'certification' AND organization IS NOT NULL AND reference_url IS NOT NULL AND checked_at IS NOT NULL))
)
SQL);
        $this->addSql('CREATE INDEX evidence_accommodation_idx ON environmental_evidence(accommodation_id)');
    }

    public function down(Schema $schema): void
    {
        // Explicitly destructive rollback; never run against valuable data without approval.
        foreach (['environmental_evidence', 'accommodation', 'emission_factor', 'journey_leg', 'journey_scenario', 'place', 'data_source'] as $table) {
            $this->addSql('DROP TABLE '.$table);
        }
    }
}
