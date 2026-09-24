<?php

declare(strict_types=1);

namespace App\Environmental;

use Doctrine\DBAL\Connection;

final readonly class PostgresEmissionFactorRepository implements EmissionFactorRepository
{
    public function __construct(private Connection $connection) {}

    public function isInitialized(): bool
    {
        try {
            return (bool) $this->connection->fetchOne("SELECT EXISTS (SELECT 1 FROM emission_factor_import WHERE source_id=:source AND status='complete')", ['source'=>AdemeEmissionFactorImporter::SOURCE_ID]);
        } catch (\Throwable) {
            return false;
        }
    }

    public function findCandidates(string $mode, ?string $subtype, string $geography, \DateTimeImmutable $date): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT f.*, i.effective_from
FROM emission_factor f
JOIN emission_factor_import i ON i.id=f.import_id AND i.status='complete'
WHERE f.mode=:mode AND f.subtype IS NOT DISTINCT FROM :subtype AND f.geography=:geography
  AND f.status='verified' AND f.selection_status='active' AND f.valid_from<=:day AND (f.valid_until IS NULL OR f.valid_until>=:day)
  AND NOT EXISTS (
    SELECT 1 FROM emission_factor newer
    JOIN emission_factor_import ni ON ni.id=newer.import_id AND ni.status='complete'
    WHERE newer.source_id=f.source_id AND newer.external_id=f.external_id
      AND newer.mode=f.mode AND newer.subtype IS NOT DISTINCT FROM f.subtype AND newer.geography=f.geography
      AND newer.selection_status='active' AND newer.valid_from<=:day AND (newer.valid_until IS NULL OR newer.valid_until>=:day)
      AND ni.effective_from>i.effective_from
  )
ORDER BY f.external_id, f.version, f.id
SQL, ['mode'=>$mode, 'subtype'=>$subtype, 'geography'=>$geography, 'day'=>$date->format('Y-m-d')]);
        return array_map(static fn(array $r): array => [
            'id'=>$r['id'], 'value'=>(float)$r['value'], 'unit'=>$r['unit'], 'mode'=>$r['mode'], 'subtype'=>$r['subtype'],
            'geography'=>$r['geography'], 'validFrom'=>$r['valid_from'], 'validUntil'=>$r['valid_until'], 'scope'=>$r['scope'],
            'occupancy'=>$r['occupancy'] === null ? null : (float)$r['occupancy'], 'version'=>$r['version'], 'sourceId'=>$r['source_id'], 'status'=>$r['status'],
            'externalId'=>$r['external_id'], 'sourceValue'=>$r['source_value'], 'sourceUnit'=>$r['source_unit'], 'sourceStatus'=>$r['source_status'],
            'sourceName'=>$r['source_name'], 'sourceAttribute'=>$r['source_attribute'], 'sourceGeography'=>$r['source_geography'], 'sourcePeriod'=>$r['source_period'],
            'upstreamSource'=>$r['upstream_source'], 'sourceUrl'=>$r['source_url'], 'sourceLicense'=>$r['source_license'], 'accessedAt'=>$r['accessed_at'],
            'checksumSha256'=>$r['checksum_sha256'], 'mappingMethod'=>$r['mapping_method'], 'mappingNotes'=>$r['mapping_notes'],
            'selectionStatus'=>$r['selection_status'],
        ], $rows);
    }
}
