<?php

declare(strict_types=1);

namespace App\Place;

use App\Provider\PlaceProvider;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final readonly class PostgresPlaceCatalog implements PlaceProvider
{
    public function __construct(private Connection $connection)
    {
    }

    public function search(string $query, int $limit = 20, int $offset = 0): array
    {
        $source = $this->source();
        $needle = self::normalize(trim($query));
        $where = $needle === '' ? '' : " AND translate(lower(p.name), 'àâäáãåçéèêëíìîïñóòôöõúùûüýÿœæ', 'aaaaaaceeeeiiiinooooouuuuyyoa') LIKE :query ESCAPE '\\'";
        $parameters = [];
        if ($needle !== '') {
            $parameters['query'] = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle).'%';
        }
        $total = (int) $this->connection->fetchOne(<<<SQL
SELECT count(*) FROM place p
JOIN place_external_reference r ON r.place_id = p.id AND r.provider_key = 'filbleu-gtfs' AND r.active = TRUE
WHERE p.source_id = 'filbleu-gtfs'$where
SQL, $parameters);
        $rows = $this->connection->fetchAllAssociative(<<<SQL
SELECT p.id, p.name, p.country_code, p.latitude, p.longitude, p.timezone
FROM place p
JOIN place_external_reference r ON r.place_id = p.id AND r.provider_key = 'filbleu-gtfs' AND r.active = TRUE
WHERE p.source_id = 'filbleu-gtfs'$where
ORDER BY translate(lower(p.name), 'àâäáãåçéèêëíìîïñóòôöõúùûüýÿœæ', 'aaaaaaceeeeiiiinooooouuuuyyoa'), p.id
LIMIT :limit OFFSET :offset
SQL, [...$parameters, 'limit' => $limit, 'offset' => $offset], [...array_fill_keys(array_keys($parameters), \Doctrine\DBAL\ParameterType::STRING), 'limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER]);

        $provenance = [
            'status' => 'verified',
            'sourceIds' => [FilBleuGtfsImporter::SOURCE_ID],
            'asOf' => $source['accessedAt'].'T00:00:00+02:00',
            'note' => 'Station commerciale location_type=1 importée du snapshot GTFS Fil Bleu vérifié.',
        ];
        $items = array_map(static fn (array $row): array => [
            'id' => $row['id'], 'name' => $row['name'], 'countryCode' => $row['country_code'],
            'latitude' => $row['latitude'] === null ? null : (float) $row['latitude'],
            'longitude' => $row['longitude'] === null ? null : (float) $row['longitude'],
            'timezone' => $row['timezone'], 'provenance' => $provenance,
        ], $rows);

        return ['items' => $items, 'page' => ['limit' => $limit, 'offset' => $offset, 'total' => $total], 'sources' => [$source]];
    }

    public function has(string $id): bool
    {
        $this->source();
        return (bool) $this->connection->fetchOne(<<<'SQL'
SELECT EXISTS(SELECT 1 FROM place_external_reference
WHERE provider_key = 'filbleu-gtfs' AND place_id = :id AND active = TRUE)
SQL, ['id' => $id]);
    }

    /** @return array<string, mixed> */
    private function source(): array
    {
        try {
            $row = $this->connection->fetchAssociative(<<<'SQL'
SELECT s.id, s.publisher, s.url, s.license, s.accessed_at, s.version, s.reuse_notes, s.data_status
FROM data_source s
WHERE s.id = 'filbleu-gtfs'
  AND EXISTS (SELECT 1 FROM place_import i WHERE i.source_id = s.id)
SQL);
        } catch (\Throwable $error) {
            throw new ServiceUnavailableHttpException(null, 'The Fil Bleu place catalogue has not been initialized.', $error);
        }
        if ($row === false) {
            throw new ServiceUnavailableHttpException(null, 'The Fil Bleu place catalogue has not been initialized.');
        }
        return [
            'id' => $row['id'], 'publisher' => $row['publisher'], 'url' => $row['url'], 'license' => $row['license'],
            'accessedAt' => $row['accessed_at'], 'version' => $row['version'], 'reuseNotes' => $row['reuse_notes'],
            'dataStatus' => $row['data_status'],
        ];
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        if (class_exists(\Transliterator::class)) {
            $normalized = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC', $value);
            if (is_string($normalized)) {
                $value = $normalized;
            }
        }
        return strtr($value, ['œ' => 'o', 'æ' => 'a']);
    }
}
