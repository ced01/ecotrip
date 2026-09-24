<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924000900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add immutable, versioned Fil Bleu GTFS schedules to the existing atomic import';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE place_import ADD COLUMN journey_ready BOOLEAN NOT NULL DEFAULT FALSE, ADD COLUMN feed_start_date DATE, ADD COLUMN feed_end_date DATE, ADD CONSTRAINT place_import_feed_dates CHECK (feed_end_date IS NULL OR feed_start_date IS NULL OR feed_end_date >= feed_start_date)");
        $this->addSql(<<<'SQL'
CREATE TABLE gtfs_route (
 import_id BIGINT NOT NULL REFERENCES place_import(id) ON DELETE CASCADE,
 external_id VARCHAR(255) NOT NULL,
 short_name VARCHAR(100) NOT NULL,
 long_name VARCHAR(255) NOT NULL,
 route_type SMALLINT NOT NULL CHECK (route_type >= 0),
 PRIMARY KEY (import_id, external_id)
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE gtfs_service (
 import_id BIGINT NOT NULL REFERENCES place_import(id) ON DELETE CASCADE,
 external_id VARCHAR(255) NOT NULL,
 monday BOOLEAN NOT NULL, tuesday BOOLEAN NOT NULL, wednesday BOOLEAN NOT NULL,
 thursday BOOLEAN NOT NULL, friday BOOLEAN NOT NULL, saturday BOOLEAN NOT NULL, sunday BOOLEAN NOT NULL,
 start_date DATE NOT NULL, end_date DATE NOT NULL CHECK (end_date >= start_date),
 PRIMARY KEY (import_id, external_id)
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE gtfs_service_exception (
 import_id BIGINT NOT NULL,
 service_id VARCHAR(255) NOT NULL,
 service_date DATE NOT NULL,
 exception_type SMALLINT NOT NULL CHECK (exception_type IN (1,2)),
 PRIMARY KEY (import_id, service_id, service_date),
 FOREIGN KEY (import_id, service_id) REFERENCES gtfs_service(import_id, external_id) ON DELETE CASCADE
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE gtfs_trip (
 import_id BIGINT NOT NULL,
 external_id VARCHAR(255) NOT NULL,
 route_id VARCHAR(255) NOT NULL,
 service_id VARCHAR(255) NOT NULL,
 headsign VARCHAR(255) NOT NULL,
 PRIMARY KEY (import_id, external_id),
 FOREIGN KEY (import_id, route_id) REFERENCES gtfs_route(import_id, external_id) ON DELETE CASCADE,
 FOREIGN KEY (import_id, service_id) REFERENCES gtfs_service(import_id, external_id) ON DELETE CASCADE
)
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE gtfs_stop_time (
 import_id BIGINT NOT NULL,
 trip_id VARCHAR(255) NOT NULL,
 stop_id VARCHAR(255) NOT NULL,
 stop_sequence INT NOT NULL CHECK (stop_sequence >= 0),
 arrival_seconds INT NOT NULL CHECK (arrival_seconds BETWEEN 0 AND 604799),
 departure_seconds INT NOT NULL CHECK (departure_seconds BETWEEN 0 AND 604799),
 pickup_type SMALLINT NOT NULL CHECK (pickup_type BETWEEN 0 AND 3),
 drop_off_type SMALLINT NOT NULL CHECK (drop_off_type BETWEEN 0 AND 3),
 PRIMARY KEY (import_id, trip_id, stop_sequence),
 FOREIGN KEY (import_id, trip_id) REFERENCES gtfs_trip(import_id, external_id) ON DELETE CASCADE,
 CHECK (departure_seconds >= arrival_seconds)
)
SQL);
        $this->addSql('CREATE INDEX gtfs_stop_time_stop_idx ON gtfs_stop_time(import_id, stop_id, trip_id, stop_sequence)');
        $this->addSql('CREATE INDEX gtfs_trip_service_idx ON gtfs_trip(import_id, service_id, route_id)');
        $this->addSql('CREATE INDEX gtfs_exception_date_idx ON gtfs_service_exception(import_id, service_date, service_id)');
        $this->addSql('CREATE INDEX place_import_journey_idx ON place_import(provider_key, journey_ready, id DESC)');
    }

    public function down(Schema $schema): void
    {
        foreach (['gtfs_stop_time', 'gtfs_trip', 'gtfs_service_exception', 'gtfs_service', 'gtfs_route'] as $table) {
            $this->addSql('DROP TABLE '.$table);
        }
        $this->addSql('ALTER TABLE place_import DROP CONSTRAINT place_import_feed_dates, DROP COLUMN journey_ready, DROP COLUMN feed_start_date, DROP COLUMN feed_end_date');
    }
}
