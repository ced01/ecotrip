<?php

declare(strict_types=1);

namespace App\Environmental;

use Doctrine\DBAL\Connection;

final readonly class PostgresEmissionFactorRepository implements EmissionFactorRepository
{
    private AdemePublicationRegistry $registry;

    public function __construct(
        private Connection $connection,
        ?string $qualifiedChecksum = null,
        ?AdemePublicationRegistry $registry = null,
    ) {
        if ($qualifiedChecksum !== null && $registry !== null) {
            throw new \InvalidArgumentException('Use either a qualified checksum or a publication registry, not both.');
        }
        $this->registry = $qualifiedChecksum !== null
            ? AdemePublicationRegistry::officialWithChecksum($qualifiedChecksum)
            : ($registry ?? new AdemePublicationRegistry());
    }

    /** Database failures deliberately propagate; false means absent/corrupt, never unavailable storage. */
    public function isInitialized(): bool
    {
        [$cte, $params] = $this->qualificationCte();
        $rows = $this->connection->fetchAllAssociative($cte.<<<'SQL'
SELECT qp.source_version, count(DISTINCT qi.id) AS import_count, count(DISTINCT qf.id) AS factor_count
FROM qualified_publication qp
LEFT JOIN qualified_import qi
  ON qi.source_id=qp.source_id AND qi.source_version=qp.source_version
LEFT JOIN qualified_factor qf ON qf.import_id=qi.id
GROUP BY qp.source_version
SQL, $params);

        if (count($rows) !== count($this->registry->publications())) {
            return false;
        }
        foreach ($rows as $row) {
            if ((int) $row['import_count'] !== 1 || (int) $row['factor_count'] !== AdemeEmissionFactorImporter::EXPECTED_FACTOR_COUNT) {
                return false;
            }
        }

        return true;
    }

    public function findCandidates(string $mode, ?string $subtype, string $geography, \DateTimeImmutable $date): array
    {
        [$cte, $params] = $this->qualificationCte();
        $params += ['mode' => $mode, 'subtype' => $subtype, 'geography' => $geography, 'day' => $date->format('Y-m-d')];
        $rows = $this->connection->fetchAllAssociative($cte.<<<'SQL'
, applicable_publication AS (
    SELECT qp.*
    FROM qualified_publication qp
    WHERE qp.effective_from<=:day
      AND NOT EXISTS (
        SELECT 1 FROM qualified_publication newer
        WHERE newer.effective_from<=:day AND newer.effective_from>qp.effective_from
      )
)
SELECT f.*, i.effective_from,
       i.publisher AS import_publisher, i.publication_url AS import_source_url,
       i.source_license AS import_source_license, i.accessed_at AS import_accessed_at,
       i.checksum_sha256 AS import_checksum_sha256, i.mapping_method AS import_mapping_method
FROM applicable_publication ap
JOIN qualified_import i ON i.source_id=ap.source_id AND i.source_version=ap.source_version
JOIN qualified_factor f ON f.import_id=i.id
WHERE f.mode=:mode AND f.subtype IS NOT DISTINCT FROM :subtype AND f.geography=:geography
  AND f.valid_from<=:day AND (f.valid_until IS NULL OR f.valid_until>=:day)
ORDER BY f.external_id, f.version, f.id
SQL, $params);

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

    /** @return array{string, array<string, string|int>} */
    private function qualificationCte(): array
    {
        $values = [];
        $params = [
            'expected_count' => AdemeEmissionFactorImporter::EXPECTED_FACTOR_COUNT,
            'publisher' => 'ADEME',
            'url' => AdemeEmissionFactorImporter::CATALOG_URL,
            'license' => AdemeEmissionFactorImporter::LICENSE,
            'accessed' => AdemeEmissionFactorImporter::ACCESSED_AT,
            'mapping' => AdemeEmissionFactorImporter::MAPPING_METHOD,
            'external_id' => '28000',
            'factor_value' => '0.151',
            'source_value' => '0,151',
            'unit' => 'kgCO2e/passenger-km',
            'source_unit' => 'kgCO2e/passager.km',
            'mode_expected' => 'public_transport',
            'subtype_expected' => 'bus_urban',
            'geography_expected' => 'FR-TM',
            'scope' => 'life_cycle',
            'source_status' => 'Valide générique',
            'source_name' => 'Autobus moyen',
            'source_attribute' => 'Agglomération de plus de 250 000 habitants',
            'source_geography' => 'France continentale',
            'source_period' => 'avr-22',
            'upstream_source' => 'UTP - Enquête TCU 2017',
            'mapping_notes' => AdemeEmissionFactorImporter::MAPPING_NOTES,
        ];
        foreach ($this->registry->publications() as $index => $publication) {
            $values[] = "(:source{$index}, :version{$index}, :checksum{$index}, CAST(:effective{$index} AS DATE))";
            $params["source{$index}"] = $publication['sourceId'];
            $params["version{$index}"] = $publication['sourceVersion'];
            $params["checksum{$index}"] = $publication['checksum'];
            $params["effective{$index}"] = $publication['effectiveFrom'];
        }

        $cte = 'WITH qualified_publication(source_id, source_version, checksum_sha256, effective_from) AS (VALUES '.implode(', ', $values).'), '.<<<'SQL'
qualified_import AS (
    SELECT i.*
    FROM qualified_publication qp
    JOIN emission_factor_import i
      ON i.source_id=qp.source_id
     AND i.source_version=qp.source_version
     AND i.checksum_sha256=qp.checksum_sha256
     AND i.effective_from=qp.effective_from
     AND i.status='complete'
     AND i.factor_count=:expected_count
     AND i.publisher=:publisher
     AND i.publication_url=:url
     AND i.source_license=:license
     AND i.accessed_at=:accessed
     AND i.mapping_method=:mapping
     AND (SELECT count(*) FROM emission_factor all_factors WHERE all_factors.import_id=i.id)=:expected_count
),
qualified_factor AS (
    SELECT f.*
    FROM qualified_import i
    JOIN emission_factor f
      ON f.import_id=i.id
     AND f.source_id=i.source_id
     AND f.version=i.source_version
     AND f.external_id=:external_id
     AND f.value=:factor_value
     AND f.source_value=:source_value
     AND f.unit=:unit
     AND f.source_unit=:source_unit
     AND f.mode=:mode_expected
     AND f.subtype=:subtype_expected
     AND f.geography=:geography_expected
     AND f.scope=:scope
     AND f.occupancy IS NULL
     AND f.valid_from=i.effective_from
     AND f.valid_until IS NULL
     AND f.status='verified'
     AND f.selection_status='active'
     AND f.source_status=:source_status
     AND f.source_name=:source_name
     AND f.source_attribute=:source_attribute
     AND f.source_geography=:source_geography
     AND f.source_period=:source_period
     AND f.upstream_source=:upstream_source
     AND f.checksum_sha256=i.checksum_sha256
     AND f.source_url=i.publication_url
     AND f.source_license=i.source_license
     AND f.accessed_at=i.accessed_at
     AND f.mapping_method=i.mapping_method
     AND f.mapping_notes=:mapping_notes
)
SQL;

        return [$cte, $params];
    }
}
