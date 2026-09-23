<?php

declare(strict_types=1);

namespace App\Place;

use Doctrine\DBAL\Connection;

final readonly class FilBleuGtfsImporter
{
    public const PROVIDER_KEY = 'filbleu-gtfs';
    public const SOURCE_ID = 'filbleu-gtfs';
    public const DOWNLOAD_URL = 'https://data.tours-metropole.fr/api/v2/catalog/datasets/horaires-temps-reel-gtfsrt-reseau-filbleu-tmvl/alternative_exports/filbleu_gtfszip';
    public const CATALOG_URL = 'https://www.data.gouv.fr/datasets/fil-bleu-syndicat-des-mobilites-gtfs-gtfs-rt';

    public function __construct(private Connection $connection)
    {
    }

    public function import(string $path, string $version, ?string $expectedSha256): GtfsImportResult
    {
        if ($version === '' || mb_strlen($version) > 100) {
            throw new \InvalidArgumentException('A non-empty source version of at most 100 characters is required.');
        }
        if (!is_string($expectedSha256) || !preg_match('/^[0-9a-f]{64}$/', $expectedSha256)) {
            throw new \InvalidArgumentException('An expected lowercase SHA-256 checksum is required.');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('The local GTFS ZIP is not readable.');
        }
        $actualSha256 = hash_file('sha256', $path);
        if (!hash_equals($expectedSha256, $actualSha256)) {
            throw new \InvalidArgumentException('GTFS checksum mismatch.');
        }

        [$feedVersion, $rows] = $this->readArchive($path);
        if ($feedVersion !== $version) {
            throw new \InvalidArgumentException(sprintf('Declared version "%s" does not match feed_version "%s".', $version, $feedVersion));
        }
        ksort($rows, SORT_STRING);
        $stationCount = count(array_filter($rows, static fn (array $row): bool => $row['locationType'] === 1));

        return $this->connection->transactional(function (Connection $db) use ($rows, $version, $expectedSha256, $stationCount): GtfsImportResult {
            $db->executeStatement(<<<'SQL'
INSERT INTO data_source (id, publisher, url, license, accessed_at, version, reuse_notes, data_status)
VALUES (:id, :publisher, :url, :license, :accessed, :version, :notes, 'verified')
ON CONFLICT (id) DO UPDATE SET publisher = EXCLUDED.publisher, url = EXCLUDED.url,
    license = EXCLUDED.license, accessed_at = EXCLUDED.accessed_at, version = EXCLUDED.version,
    reuse_notes = EXCLUDED.reuse_notes, data_status = EXCLUDED.data_status
SQL, [
                'id' => self::SOURCE_ID,
                'publisher' => 'Tours Métropole Val de Loire / Fil Bleu (Tours)',
                'url' => self::CATALOG_URL,
                'license' => 'Licence Ouverte 2.0',
                'accessed' => '2026-09-23',
                'version' => $version,
                'notes' => 'Snapshot GTFS local vérifié; offre théorique, mise à jour annoncée au maximum quotidiennement. Artefact: '.self::DOWNLOAD_URL,
            ]);
            $importId = (int) $db->fetchOne(<<<'SQL'
INSERT INTO place_import (source_id, provider_key, feed_version, checksum_sha256, station_count, reference_count)
VALUES (:source, :provider, :version, :checksum, :stations, :references)
RETURNING id
SQL, [
                'source' => self::SOURCE_ID, 'provider' => self::PROVIDER_KEY, 'version' => $version,
                'checksum' => $expectedSha256, 'stations' => $stationCount, 'references' => count($rows),
            ]);

            $db->executeStatement('UPDATE place_external_reference SET active = FALSE WHERE provider_key = :provider', ['provider' => self::PROVIDER_KEY]);

            foreach ($rows as $externalId => $row) {
                $placeId = null;
                if ($row['locationType'] === 1) {
                    $placeId = self::internalId($externalId);
                    $existingReference = $db->fetchAssociative(
                        'SELECT place_id, location_type FROM place_external_reference WHERE provider_key = :provider AND external_id = :external',
                        ['provider' => self::PROVIDER_KEY, 'external' => $externalId],
                    );
                    if ($existingReference !== false && ($existingReference['place_id'] !== $placeId || (int) $existingReference['location_type'] !== 1)) {
                        throw new \InvalidArgumentException('External reference identity collision.');
                    }
                    $owner = $db->fetchOne('SELECT source_id FROM place WHERE id = :id', ['id' => $placeId]);
                    if ($owner !== false && $owner !== self::SOURCE_ID) {
                        throw new \InvalidArgumentException('Deterministic internal ID collision.');
                    }
                    $db->executeStatement(<<<'SQL'
INSERT INTO place (id, name, country_code, latitude, longitude, timezone, source_id, data_status)
VALUES (:id, :name, 'FR', :latitude, :longitude, :timezone, :source, 'verified')
ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, country_code = EXCLUDED.country_code,
    latitude = EXCLUDED.latitude, longitude = EXCLUDED.longitude, timezone = EXCLUDED.timezone,
    source_id = EXCLUDED.source_id, data_status = EXCLUDED.data_status
SQL, [
                        'id' => $placeId, 'name' => $row['name'], 'latitude' => $row['latitude'],
                        'longitude' => $row['longitude'], 'timezone' => $row['timezone'], 'source' => self::SOURCE_ID,
                    ]);
                }

                $existingReference = $db->fetchAssociative(
                    'SELECT place_id, location_type FROM place_external_reference WHERE provider_key = :provider AND external_id = :external',
                    ['provider' => self::PROVIDER_KEY, 'external' => $externalId],
                );
                if ($existingReference !== false && (($existingReference['place_id'] ?? null) !== $placeId || (int) $existingReference['location_type'] !== $row['locationType'])) {
                    throw new \InvalidArgumentException('External reference was reassigned.');
                }
                $db->executeStatement(<<<'SQL'
INSERT INTO place_external_reference
    (provider_key, external_id, place_id, parent_external_id, location_type, active, first_seen_import_id, last_seen_import_id)
VALUES (:provider, :external, :place, :parent, :type, TRUE, :import, :import)
ON CONFLICT (provider_key, external_id) DO UPDATE SET parent_external_id = EXCLUDED.parent_external_id,
    active = TRUE, last_seen_import_id = EXCLUDED.last_seen_import_id
SQL, [
                    'provider' => self::PROVIDER_KEY, 'external' => $externalId, 'place' => $placeId,
                    'parent' => $row['parent'] === '' ? null : $row['parent'], 'type' => $row['locationType'], 'import' => $importId,
                ]);
            }

            return new GtfsImportResult($stationCount, count($rows), $importId, $version, $expectedSha256);
        });
    }

    public static function internalId(string $externalId): string
    {
        // Opaque and deterministic: provider key + first 40 hexadecimal SHA-256 characters.
        return 'filbleu-'.substr(hash('sha256', self::SOURCE_ID."\0".$externalId), 0, 40);
    }

    /** @return array{string, array<string, array{name: string, latitude: float, longitude: float, timezone: string, locationType: int, parent: string}>} */
    private function readArchive(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \InvalidArgumentException('Invalid GTFS ZIP.');
        }
        try {
            foreach (['stops.txt', 'feed_infos.txt'] as $required) {
                $stat = $zip->statName($required);
                if ($stat === false || ($stat['size'] ?? 0) <= 0 || ($stat['size'] ?? 0) > 10_000_000) {
                    throw new \InvalidArgumentException('GTFS ZIP is incomplete or a required member is too large: '.$required);
                }
            }
            $feed = $this->csv($zip, 'feed_infos.txt');
            if (count($feed) !== 1 || !isset($feed[0]['feed_version'])) {
                throw new \InvalidArgumentException('feed_infos.txt must contain exactly one feed_version row.');
            }
            $feedVersion = trim($feed[0]['feed_version']);
            if ($feedVersion === '') {
                throw new \InvalidArgumentException('feed_version is required.');
            }
            $stops = $this->csv($zip, 'stops.txt');
        } finally {
            $zip->close();
        }

        $rows = [];
        foreach ($stops as $stop) {
            foreach (['stop_id', 'stop_name', 'stop_lat', 'stop_lon', 'location_type', 'parent_station'] as $field) {
                if (!array_key_exists($field, $stop)) {
                    throw new \InvalidArgumentException('Missing required stops.txt column: '.$field);
                }
            }
            $id = trim($stop['stop_id']);
            $name = trim($stop['stop_name']);
            $type = filter_var($stop['location_type'], FILTER_VALIDATE_INT);
            $lat = filter_var($stop['stop_lat'], FILTER_VALIDATE_FLOAT);
            $lon = filter_var($stop['stop_lon'], FILTER_VALIDATE_FLOAT);
            $timezone = trim($stop['stop_timezone'] ?? '') ?: 'Europe/Paris';
            if ($id === '' || mb_strlen($id) > 255 || $name === '' || mb_strlen($name) > 255 || !in_array($type, [0, 1], true)
                || $lat === false || $lat < -90 || $lat > 90 || $lon === false || $lon < -180 || $lon > 180
                || $timezone !== 'Europe/Paris') {
                throw new \InvalidArgumentException('Invalid mandatory GTFS stop data.');
            }
            $candidate = ['name' => $name, 'latitude' => (float) $lat, 'longitude' => (float) $lon, 'timezone' => $timezone,
                'locationType' => $type, 'parent' => trim($stop['parent_station'])];
            if (isset($rows[$id]) && $rows[$id] !== $candidate) {
                throw new \InvalidArgumentException('Contradictory duplicate stop_id: '.$id);
            }
            $rows[$id] = $candidate;
        }
        if ($rows === []) {
            throw new \InvalidArgumentException('No GTFS stops found.');
        }
        foreach ($rows as $row) {
            if ($row['locationType'] === 0 && $row['parent'] !== '' && (!isset($rows[$row['parent']]) || $rows[$row['parent']]['locationType'] !== 1)) {
                throw new \InvalidArgumentException('Physical stop references an unknown commercial station.');
            }
        }

        return [$feedVersion, $rows];
    }

    /** @return list<array<string, string>> */
    private function csv(\ZipArchive $zip, string $name): array
    {
        $stream = $zip->getStream($name);
        if ($stream === false) {
            throw new \InvalidArgumentException('Cannot read '.$name);
        }
        try {
            $headers = fgetcsv($stream, 1_000_000, ',', '"', '');
            if (!is_array($headers) || $headers === [] || count($headers) !== count(array_unique($headers))) {
                throw new \InvalidArgumentException('Invalid CSV header in '.$name);
            }
            $result = [];
            while (($values = fgetcsv($stream, 1_000_000, ',', '"', '')) !== false) {
                if ($values === [null] || $values === []) {
                    continue;
                }
                if (count($values) !== count($headers)) {
                    throw new \InvalidArgumentException('Malformed CSV row in '.$name);
                }
                $result[] = array_combine($headers, $values);
            }
            return $result;
        } finally {
            fclose($stream);
        }
    }
}
