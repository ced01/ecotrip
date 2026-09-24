<?php

declare(strict_types=1);

namespace App\Journey;

use App\Place\FilBleuGtfsImporter;
use App\Provider\ProviderUnavailable;
use Doctrine\DBAL\Connection;

final readonly class PostgresJourneyScheduleRepository implements JourneyScheduleRepository
{
    public function __construct(private Connection $connection) {}

    public function direct(string $originStation, string $destinationStation, \DateTimeImmutable $date): array
    {
        $weekday = strtolower($date->format('l'));
        if (!in_array($weekday, ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'], true)) throw new \LogicException('Invalid weekday.');
        // Distinguish a valid snapshot with no matching service from absent or
        // corrupt imported journey data.
        $this->source();
        try {
            $rows = $this->connection->fetchAllAssociative(<<<SQL
WITH snapshot AS (
 SELECT id FROM place_import WHERE provider_key=:provider AND journey_ready=TRUE ORDER BY id DESC LIMIT 1
), origin_stops AS (
 SELECT external_id FROM place_external_reference
 WHERE provider_key=:provider AND active=TRUE
   AND ((external_id=:origin AND location_type=1) OR (parent_external_id=:origin AND location_type=0))
), destination_stops AS (
 SELECT external_id FROM place_external_reference
 WHERE provider_key=:provider AND active=TRUE
   AND ((external_id=:destination AND location_type=1) OR (parent_external_id=:destination AND location_type=0))
)
SELECT t.external_id AS trip, COALESCE(NULLIF(r.short_name,''), r.long_name) AS route_name,
       departure.stop_sequence AS departure_sequence, arrival.stop_sequence AS arrival_sequence,
       departure.departure_seconds, arrival.arrival_seconds
FROM snapshot s
JOIN gtfs_trip t ON t.import_id=s.id
JOIN gtfs_route r ON r.import_id=t.import_id AND r.external_id=t.route_id AND r.route_type=3
JOIN gtfs_service service ON service.import_id=t.import_id AND service.external_id=t.service_id
LEFT JOIN gtfs_service_exception exception ON exception.import_id=service.import_id AND exception.service_id=service.external_id AND exception.service_date=:date
JOIN gtfs_stop_time departure ON departure.import_id=t.import_id AND departure.trip_id=t.external_id AND departure.stop_id IN (SELECT external_id FROM origin_stops) AND departure.pickup_type<>1
JOIN gtfs_stop_time arrival ON arrival.import_id=t.import_id AND arrival.trip_id=t.external_id AND arrival.stop_id IN (SELECT external_id FROM destination_stops) AND arrival.drop_off_type<>1 AND arrival.stop_sequence>departure.stop_sequence
WHERE CASE WHEN exception.exception_type IS NOT NULL THEN exception.exception_type=1
           ELSE service.start_date<=:date AND service.end_date>=:date AND service.$weekday=TRUE END
ORDER BY departure.departure_seconds, (arrival.arrival_seconds-departure.departure_seconds), t.external_id, departure.stop_sequence, arrival.stop_sequence
LIMIT 20
SQL, ['provider'=>FilBleuGtfsImporter::PROVIDER_KEY,'origin'=>$originStation,'destination'=>$destinationStation,'date'=>$date->format('Y-m-d')]);
            return array_map(static fn(array $row): array => [
                'trip'=>(string)$row['trip'],
                'routeName'=>(string)$row['route_name'],
                'departureSequence'=>(int)$row['departure_sequence'],
                'arrivalSequence'=>(int)$row['arrival_sequence'],
                'departureSeconds'=>(int)$row['departure_seconds'],
                'arrivalSeconds'=>(int)$row['arrival_seconds'],
            ], $rows);
        } catch (\Throwable $error) {
            throw new ProviderUnavailable('Fil Bleu schedule storage is unavailable.', 0, $error);
        }
    }

    public function source(): array
    {
        try {
            $row=$this->connection->fetchAssociative(<<<'SQL'
SELECT s.id,s.publisher,s.url,s.license,s.accessed_at,s.version,s.reuse_notes,s.data_status
FROM data_source s JOIN place_import i ON i.source_id=s.id
WHERE s.id=:source AND i.provider_key=:provider AND i.journey_ready=TRUE
  AND i.feed_version=s.version AND length(i.checksum_sha256)=64
ORDER BY i.id DESC LIMIT 1
SQL,['source'=>FilBleuGtfsImporter::SOURCE_ID,'provider'=>FilBleuGtfsImporter::PROVIDER_KEY]);
        } catch (\Throwable $error) { throw new ProviderUnavailable('Fil Bleu schedule storage is unavailable.',0,$error); }
        if ($row===false || $row['license']!=='Licence Ouverte 2.0' || $row['data_status']!=='verified') throw new ProviderUnavailable('No valid Fil Bleu schedule snapshot.');
        return ['id'=>$row['id'],'publisher'=>$row['publisher'],'url'=>$row['url'],'license'=>$row['license'],'accessedAt'=>$row['accessed_at'],'version'=>$row['version'],'reuseNotes'=>$row['reuse_notes'],'dataStatus'=>$row['data_status']];
    }
}
