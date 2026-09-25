<?php

declare(strict_types=1);

namespace App\Environmental;

use Doctrine\DBAL\Connection;

final readonly class PostgresEmissionFactorRepository implements EmissionFactorRepository
{
    public function __construct(private Connection $connection, private ?string $qualifiedChecksum = null) {}

    /** Database failures deliberately propagate; false means present-but-absent/corrupt, never unavailable storage. */
    public function isInitialized(): bool
    {
        $checksum = $this->qualifiedChecksum ?? AdemeEmissionFactorImporter::OFFICIAL_SHA256;
        $imports = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT i.id, i.factor_count, count(f.id) AS actual_count,
       count(f.id) FILTER (WHERE f.external_id='28000' AND f.mode='public_transport' AND f.subtype='bus_urban'
         AND f.geography='FR-TM' AND f.scope='life_cycle' AND f.unit='kgCO2e/passenger-km'
         AND f.status='verified' AND f.selection_status='active' AND f.version=i.source_version
         AND f.checksum_sha256=i.checksum_sha256 AND f.mapping_method=i.mapping_method
         AND f.source_url=i.publication_url AND f.source_license=i.source_license AND f.accessed_at=i.accessed_at) AS qualified_count
FROM emission_factor_import i
LEFT JOIN emission_factor f ON f.import_id=i.id
WHERE i.source_id=:source AND i.source_version=:version AND i.checksum_sha256=:checksum
  AND i.status='complete' AND i.factor_count=:expected AND i.effective_from=:effective
  AND i.publisher='ADEME' AND i.publication_url=:url AND i.source_license=:license AND i.mapping_method=:mapping
GROUP BY i.id, i.factor_count
SQL, [
            'source'=>AdemeEmissionFactorImporter::SOURCE_ID,
            'version'=>AdemeEmissionFactorImporter::SOURCE_VERSION,
            'checksum'=>$checksum,
            'expected'=>AdemeEmissionFactorImporter::EXPECTED_FACTOR_COUNT,
            'effective'=>AdemeEmissionFactorImporter::EFFECTIVE_FROM,
            'url'=>AdemeEmissionFactorImporter::CATALOG_URL,
            'license'=>AdemeEmissionFactorImporter::LICENSE,
            'mapping'=>AdemeEmissionFactorImporter::MAPPING_METHOD,
        ]);

        return count($imports) === 1
            && (int) $imports[0]['actual_count'] === AdemeEmissionFactorImporter::EXPECTED_FACTOR_COUNT
            && (int) $imports[0]['qualified_count'] === AdemeEmissionFactorImporter::EXPECTED_FACTOR_COUNT;
    }

    public function findCandidates(string $mode, ?string $subtype, string $geography, \DateTimeImmutable $date): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT f.*, i.effective_from,
       i.publisher AS import_publisher, i.publication_url AS import_source_url,
       i.source_license AS import_source_license, i.accessed_at AS import_accessed_at,
       i.checksum_sha256 AS import_checksum_sha256, i.mapping_method AS import_mapping_method
FROM emission_factor f
JOIN emission_factor_import i ON i.id=f.import_id AND i.status='complete'
WHERE f.mode=:mode AND f.subtype IS NOT DISTINCT FROM :subtype AND f.geography=:geography
  AND f.status='verified' AND f.selection_status='active' AND f.valid_from<=:day AND (f.valid_until IS NULL OR f.valid_until>=:day)
  AND f.checksum_sha256=i.checksum_sha256 AND f.mapping_method=i.mapping_method
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
            'upstreamSource'=>$r['upstream_source'], 'sourceUrl'=>$r['import_source_url'], 'sourceLicense'=>$r['import_source_license'], 'accessedAt'=>$r['import_accessed_at'],
            'checksumSha256'=>$r['import_checksum_sha256'], 'mappingMethod'=>$r['import_mapping_method'], 'mappingNotes'=>$r['mapping_notes'],
        ], $rows);
    }
}
