<?php

declare(strict_types=1);

namespace App\Environmental;

use Doctrine\DBAL\Connection;

/** Imports only the explicitly reviewed ADEME V23.6 publication. No network access. */
final readonly class AdemeEmissionFactorImporter
{
    public const SOURCE_ID = 'ademe-base-carbone';
    public const SOURCE_VERSION = 'V23.6';
    public const OFFICIAL_SHA256 = '01472bc24743c0265b649407508dfce896f15a5c11c0f612f6b47a5625b02653';
    public const CATALOG_URL = 'https://data.ademe.fr/datasets/base-carboner';
    public const EXPORT_URL = 'https://data.ademe.fr/data-fair/api/v1/datasets/base-carboner/full';
    public const LICENSE = 'Licence Ouverte / Open Licence (Etalab)';
    public const ACCESSED_AT = '2026-09-24';
    public const EFFECTIVE_FROM = '2026-06-30';
    public const MAPPING_METHOD = 'ademe-v23.6-explicit-map-v2';
    public const MAPPING_NOTES = '28000 explicitement autorisé pour le sous-type bus_urban uniquement. Les lignes Poste officielles Carburant (amont/combustion)=0,129 et Fabrication=0,0225 qualifient le périmètre life_cycle. Applicabilité démographique: Tours Métropole Val de Loire (EPCI 243700754), 301 339 habitants en 2023, INSEE https://www.insee.fr/fr/statistiques/1405599?geo=EPCI-243700754 (consulté le 2026-09-24). Moyenne de classe démographique, jamais mesure Fil Bleu; aucune occupation inventée. Période source avr-22 conservée textuellement, sans date de fin inventée.';
    public const EXPECTED_FACTOR_COUNT = 1;
    /** @var list<string> */
    public const EXPECTED_IDS = ['28000'];

    private const REQUIRED_HEADERS = [
        'Type Ligne', "Identifiant de l'élément", 'Structure', "Type de l'élément", "Statut de l'élément",
        'Nom base français', 'Nom attribut français', 'Unité français', 'Source', 'Localisation géographique',
        'Date de création', 'Date de modification', 'Période de validité', 'Type poste', 'Total poste non décomposé',
    ];
    /** @var array<string, string> Official Poste rows proving that the Elément total covers operation and manufacture. */
    private const EXPECTED_POSTS = [
        'Carburant (amont/combustion)' => '0,129',
        'Fabrication' => '0,0225',
    ];

    /** @param list<string>|null $qualifiedChecksums Test fixtures may inject their own reviewed derived checksum; production accepts only the official snapshot. */
    public function __construct(private Connection $connection, private ?array $qualifiedChecksums = null) {}

    public function import(string $path, string $sourceVersion, string $expectedSha256, ?string $effectiveFrom = null): EmissionFactorImportResult
    {
        if ($sourceVersion !== self::SOURCE_VERSION) {
            throw new \InvalidArgumentException('Unsupported ADEME publication version; only qualified V23.6 is accepted.');
        }
        if (!preg_match('/^[0-9a-f]{64}$/D', $expectedSha256)) {
            throw new \InvalidArgumentException('An expected lowercase SHA-256 checksum is required.');
        }
        $qualified = $this->qualifiedChecksums ?? [self::OFFICIAL_SHA256];
        if (!in_array($expectedSha256, $qualified, true)) {
            throw new \InvalidArgumentException('Unqualified ADEME checksum for V23.6; only reviewed snapshots are accepted.');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('The local ADEME CSV is not readable.');
        }
        $actual = hash_file('sha256', $path);
        if (!hash_equals($expectedSha256, $actual)) {
            throw new \InvalidArgumentException('ADEME checksum mismatch; nothing was imported.');
        }
        $effective = $effectiveFrom ?? self::EFFECTIVE_FROM;
        $effectiveDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $effective);
        if ($effectiveDate === false || $effectiveDate->format('Y-m-d') !== $effective) {
            throw new \InvalidArgumentException('Effective-from must be an exact YYYY-MM-DD date.');
        }
        if ($effective !== self::EFFECTIVE_FROM) {
            throw new \InvalidArgumentException('Effective-from is not qualified for the V23.6 mapping.');
        }
        $rows = $this->readAndValidate($path);

        return $this->connection->transactional(function (Connection $db) use ($rows, $sourceVersion, $expectedSha256, $effective): EmissionFactorImportResult {
            // Serialize competing imports for this publication before checking identity.
            $db->executeQuery('SELECT pg_advisory_xact_lock(hashtext(:identity))', ['identity' => self::SOURCE_ID."\0".$sourceVersion]);
            $existing = $db->fetchAssociative('SELECT id, checksum_sha256, factor_count FROM emission_factor_import WHERE source_id=:source AND source_version=:version', ['source'=>self::SOURCE_ID, 'version'=>$sourceVersion]);
            if ($existing !== false) {
                if (!hash_equals((string) $existing['checksum_sha256'], $expectedSha256)) {
                    throw new \InvalidArgumentException('Publication identity collision: this source version already has a different checksum.');
                }
                if ((int) $existing['factor_count'] !== count($rows)) {
                    throw new \InvalidArgumentException('Existing ADEME import is corrupt: factor count differs.');
                }
                $registry = hash_equals(self::OFFICIAL_SHA256, $expectedSha256)
                    ? AdemePublicationRegistry::official()
                    : AdemePublicationRegistry::arbitraryChecksumForRepositoryTest($expectedSha256);
                if (!(new PostgresEmissionFactorRepository($db, $registry))->hasCompleteQualifiedStructure()) {
                    throw new \InvalidArgumentException('Existing ADEME import is corrupt: persisted publication integrity differs.');
                }
                return new EmissionFactorImportResult((int) $existing['id'], $sourceVersion, $expectedSha256, (int) $existing['factor_count'], true);
            }
            $db->executeStatement(<<<'SQL'
INSERT INTO data_source (id,publisher,url,license,accessed_at,version,reuse_notes,data_status)
VALUES (:id,'ADEME',:url,:license,:accessed,:version,:notes,'verified')
ON CONFLICT (id) DO NOTHING
SQL, ['id'=>self::SOURCE_ID, 'url'=>self::CATALOG_URL, 'license'=>self::LICENSE, 'accessed'=>self::ACCESSED_AT, 'version'=>$sourceVersion, 'notes'=>'Base Carbone® importée depuis un export local épinglé; aucune requête réseau pendant une recherche.']);
            $importId = (int) $db->fetchOne(<<<'SQL'
INSERT INTO emission_factor_import
(source_id,source_version,checksum_sha256,accessed_at,effective_from,factor_count,status,publisher,publication_url,source_license,mapping_method)
VALUES (:source,:version,:checksum,:accessed,:effective,:count,'complete','ADEME',:url,:license,:mapping)
RETURNING id
SQL, ['source'=>self::SOURCE_ID,'version'=>$sourceVersion,'checksum'=>$expectedSha256,'accessed'=>self::ACCESSED_AT,'effective'=>$effective,'count'=>count($rows),'url'=>self::CATALOG_URL,'license'=>self::LICENSE,'mapping'=>self::MAPPING_METHOD]);
            foreach ($rows as $row) {
                $id = PostgresEmissionFactorRepository::deterministicFactorId(self::SOURCE_ID, (string) $row['externalId'], $sourceVersion);
                $db->insert('emission_factor', [
                    'id'=>$id, 'value'=>$row['value'], 'unit'=>'kgCO2e/passenger-km', 'mode'=>'public_transport', 'subtype'=>'bus_urban',
                    'geography'=>'FR-TM', 'valid_from'=>$effective, 'valid_until'=>null, 'scope'=>'life_cycle', 'occupancy'=>null,
                    'version'=>$sourceVersion, 'source_id'=>self::SOURCE_ID, 'status'=>'verified', 'import_id'=>$importId,
                    'external_id'=>$row['externalId'], 'source_value'=>$row['sourceValue'], 'source_unit'=>$row['sourceUnit'],
                    'source_status'=>$row['sourceStatus'], 'source_name'=>$row['sourceName'], 'source_attribute'=>$row['sourceAttribute'],
                    'source_geography'=>$row['sourceGeography'], 'source_period'=>$row['sourcePeriod'], 'upstream_source'=>$row['upstreamSource'],
                    'source_url'=>self::CATALOG_URL, 'source_license'=>self::LICENSE, 'accessed_at'=>self::ACCESSED_AT,
                    'checksum_sha256'=>$expectedSha256, 'mapping_method'=>self::MAPPING_METHOD,
                    'mapping_notes'=>self::MAPPING_NOTES,
                ]);
            }
            return new EmissionFactorImportResult($importId, $sourceVersion, $expectedSha256, count($rows));
        });
    }

    /** @return list<array<string, string|float>> */
    private function readAndValidate(string $path): array
    {
        $bytes = file_get_contents($path);
        if ($bytes === false || $bytes === '' || str_starts_with($bytes, "\xEF\xBB\xBF") || mb_check_encoding($bytes, 'UTF-8')) {
            throw new \InvalidArgumentException('Invalid or empty ADEME CSV encoding; expected the pinned Windows-1252 export.');
        }
        $utf8 = iconv('Windows-1252', 'UTF-8', $bytes);
        if ($utf8 === false || iconv('UTF-8', 'Windows-1252', $utf8) !== $bytes) {
            throw new \InvalidArgumentException('ADEME CSV must be losslessly encoded as Windows-1252/ISO-8859-1.');
        }
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $utf8);
        rewind($stream);
        $headers = fgetcsv($stream, 1_000_000, ';', '"', '');
        if (!is_array($headers) || count($headers) < 60 || count($headers) !== count(array_unique($headers))) {
            throw new \InvalidArgumentException('Invalid ADEME CSV separator or header.');
        }
        foreach (self::REQUIRED_HEADERS as $required) {
            if (!in_array($required, $headers, true)) throw new \InvalidArgumentException('Missing ADEME CSV column: '.$required.'.');
        }
        $selected = [];
        $posts = [];
        while (($values = fgetcsv($stream, 1_000_000, ';', '"', '')) !== false) {
            if ($values === [null] || $values === []) continue;
            if (count($values) !== count($headers)) throw new \InvalidArgumentException('Malformed ADEME CSV row.');
            $row = array_combine($headers, $values);
            $external = trim($row["Identifiant de l'élément"]);
            if (!in_array($external, self::EXPECTED_IDS, true)) continue;
            $lineType = trim($row['Type Ligne']);
            if ($lineType === 'Elément') {
                $candidate = $this->mapElement($row, $external);
                if (isset($selected[$external]) && $selected[$external] !== $candidate) throw new \InvalidArgumentException('Contradictory duplicate ADEME identifier: '.$external.'.');
                $selected[$external] = $candidate;
            } elseif ($lineType === 'Poste') {
                $this->validateCommonSourceFields($row, $external);
                $type = trim($row['Type poste']);
                $value = trim($row['Total poste non décomposé']);
                if (!array_key_exists($type, self::EXPECTED_POSTS) || self::EXPECTED_POSTS[$type] !== $value) {
                    throw new \InvalidArgumentException('Missing, modified or unsupported ADEME Poste proof for '.$external.'.');
                }
                if (isset($posts[$external][$type]) && $posts[$external][$type] !== $value) {
                    throw new \InvalidArgumentException('Contradictory ADEME Poste proof for '.$external.'.');
                }
                $posts[$external][$type] = $value;
            } else {
                throw new \InvalidArgumentException('Unsupported ADEME line type for expected identifier '.$external.'.');
            }
        }
        fclose($stream);
        $missing = array_diff(self::EXPECTED_IDS, array_keys($selected));
        if ($missing !== []) throw new \InvalidArgumentException('Expected ADEME identifier missing: '.implode(', ', $missing).'.');
        foreach (self::EXPECTED_IDS as $external) {
            if (($posts[$external] ?? []) !== self::EXPECTED_POSTS) {
                throw new \InvalidArgumentException('Complete official ADEME Poste proof missing for '.$external.'; life_cycle cannot be activated.');
            }
        }
        return array_values($selected);
    }

    /** @param array<string,string> $row @return array<string,string|float> */
    private function mapElement(array $row, string $external): array
    {
        $this->validateCommonSourceFields($row, $external);
        $requiredValues = ["Type de l'élément"=>"Facteur d'émission", "Statut de l'élément"=>'Valide générique', 'Nom base français'=>'Autobus moyen', 'Nom attribut français'=>'Agglomération de plus de 250 000 habitants', 'Unité français'=>'kgCO2e/passager.km'];
        foreach ($requiredValues as $column=>$expected) {
            if (trim($row[$column]) !== $expected) throw new \InvalidArgumentException('Unsupported ADEME mapping for '.$external.' ('.$column.').');
        }
        $raw = trim($row['Total poste non décomposé']);
        if (!preg_match('/^(0|[1-9]\d*),\d+$/D', $raw)) throw new \InvalidArgumentException('Invalid French decimal ADEME value for '.$external.'.');
        $value = (float) str_replace(',', '.', $raw);
        if ($value < 0 || !is_finite($value)) throw new \InvalidArgumentException('Invalid ADEME factor value.');
        return ['externalId'=>$external,'value'=>$value,'sourceValue'=>$raw,'sourceUnit'=>trim($row['Unité français']),'sourceStatus'=>trim($row["Statut de l'élément"]),'sourceName'=>trim($row['Nom base français']),'sourceAttribute'=>trim($row['Nom attribut français']),'sourceGeography'=>trim($row['Localisation géographique']),'sourcePeriod'=>trim($row['Période de validité']),'upstreamSource'=>trim($row['Source'])];
    }

    /** @param array<string,string> $row */
    private function validateCommonSourceFields(array $row, string $external): void
    {
        if (trim($row['Structure']) !== 'élément décomposé par poste' || trim($row['Localisation géographique']) !== 'France continentale') {
            throw new \InvalidArgumentException('Unsupported ADEME mapping for '.$external.'.');
        }
        $created = $this->sourceDate($row['Date de création'], 'Date de création');
        $modified = $this->sourceDate($row['Date de modification'], 'Date de modification');
        if ($created > $modified) throw new \InvalidArgumentException('ADEME creation date must not follow modification date.');
        if (trim($row['Source']) !== 'UTP - Enquête TCU 2017' || trim($row['Période de validité']) !== 'avr-22') {
            throw new \InvalidArgumentException('ADEME source or exact source period is inconsistent with the qualified mapping.');
        }
    }

    private function sourceDate(string $raw, string $column): \DateTimeImmutable
    {
        $raw = trim($raw);
        $date = \DateTimeImmutable::createFromFormat('!d/m/Y', $raw);
        if (!$date || $date->format('d/m/Y') !== $raw) throw new \InvalidArgumentException('Invalid ADEME date: '.$column.'.');
        return $date;
    }
}
