<?php

declare(strict_types=1);

namespace App\Environmental;

use Doctrine\DBAL\Connection;

/** Imports only the explicitly reviewed ADEME identifiers. No network access. */
final readonly class AdemeEmissionFactorImporter
{
    public const SOURCE_ID = 'ademe-base-carbone';
    public const CATALOG_URL = 'https://data.ademe.fr/datasets/base-carboner';
    public const EXPORT_URL = 'https://data.ademe.fr/data-fair/api/v1/datasets/base-carboner/full';
    public const LICENSE = 'Licence Ouverte / Open Licence (Etalab)';
    public const ACCESSED_AT = '2026-09-24';
    public const MAPPING_METHOD = 'ademe-v23.6-explicit-map-v1';
    private const REQUIRED_HEADERS = [
        'Type Ligne', "Identifiant de l'élément", 'Structure', "Type de l'élément", "Statut de l'élément",
        'Nom base français', 'Nom attribut français', 'Unité français', 'Source', 'Localisation géographique',
        'Date de création', 'Date de modification', 'Période de validité', 'Total poste non décomposé',
    ];
    private const EXPECTED_IDS = ['28000'];

    public function __construct(private Connection $connection) {}

    public function import(string $path, string $sourceVersion, string $expectedSha256, ?\DateTimeImmutable $effectiveFrom = null): EmissionFactorImportResult
    {
        if ($sourceVersion === '' || mb_strlen($sourceVersion) > 100) {
            throw new \InvalidArgumentException('A non-empty source version of at most 100 characters is required.');
        }
        if (!preg_match('/^[0-9a-f]{64}$/D', $expectedSha256)) {
            throw new \InvalidArgumentException('An expected lowercase SHA-256 checksum is required.');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('The local ADEME CSV is not readable.');
        }
        $actual = hash_file('sha256', $path);
        if (!hash_equals($expectedSha256, $actual)) {
            throw new \InvalidArgumentException('ADEME checksum mismatch; nothing was imported.');
        }
        $rows = $this->readAndValidate($path);
        $effective = ($effectiveFrom ?? new \DateTimeImmutable('2026-06-30'))->format('Y-m-d');

        return $this->connection->transactional(function (Connection $db) use ($rows, $sourceVersion, $expectedSha256, $effective): EmissionFactorImportResult {
            $existing = $db->fetchAssociative('SELECT id, checksum_sha256, factor_count FROM emission_factor_import WHERE source_id=:source AND source_version=:version', ['source'=>self::SOURCE_ID, 'version'=>$sourceVersion]);
            if ($existing !== false) {
                if (!hash_equals((string) $existing['checksum_sha256'], $expectedSha256)) {
                    throw new \InvalidArgumentException('Publication identity collision: this source version already has a different checksum.');
                }
                return new EmissionFactorImportResult((int) $existing['id'], $sourceVersion, $expectedSha256, (int) $existing['factor_count'], true);
            }
            $db->executeStatement(<<<'SQL'
INSERT INTO data_source (id,publisher,url,license,accessed_at,version,reuse_notes,data_status)
VALUES (:id,'ADEME',:url,:license,:accessed,:version,:notes,'verified')
ON CONFLICT (id) DO UPDATE SET publisher=EXCLUDED.publisher,url=EXCLUDED.url,license=EXCLUDED.license,accessed_at=EXCLUDED.accessed_at,version=EXCLUDED.version,reuse_notes=EXCLUDED.reuse_notes,data_status=EXCLUDED.data_status
SQL, ['id'=>self::SOURCE_ID, 'url'=>self::CATALOG_URL, 'license'=>self::LICENSE, 'accessed'=>self::ACCESSED_AT, 'version'=>$sourceVersion, 'notes'=>'Base Carbone® importée depuis un export local épinglé; aucune requête réseau pendant une recherche.']);
            $importId = (int) $db->fetchOne('INSERT INTO emission_factor_import (source_id,source_version,checksum_sha256,accessed_at,effective_from,factor_count,status) VALUES (:source,:version,:checksum,:accessed,:effective,:count,\'complete\') RETURNING id', ['source'=>self::SOURCE_ID,'version'=>$sourceVersion,'checksum'=>$expectedSha256,'accessed'=>self::ACCESSED_AT,'effective'=>$effective,'count'=>count($rows)]);
            foreach ($rows as $row) {
                $id = 'ademe-'.$row['externalId'].'-'.substr(hash('sha256', self::SOURCE_ID."\0".$row['externalId']."\0".$sourceVersion), 0, 32);
                $db->insert('emission_factor', [
                    'id'=>$id, 'value'=>$row['value'], 'unit'=>'kgCO2e/passenger-km', 'mode'=>'public_transport', 'subtype'=>null,
                    'geography'=>'FR-TM', 'valid_from'=>$effective, 'valid_until'=>null, 'scope'=>'life_cycle', 'occupancy'=>null,
                    'version'=>$sourceVersion, 'source_id'=>self::SOURCE_ID, 'status'=>'verified', 'import_id'=>$importId,
                    'external_id'=>$row['externalId'], 'source_value'=>$row['sourceValue'], 'source_unit'=>$row['sourceUnit'],
                    'source_status'=>$row['sourceStatus'], 'source_name'=>$row['sourceName'], 'source_attribute'=>$row['sourceAttribute'],
                    'source_geography'=>$row['sourceGeography'], 'source_period'=>$row['sourcePeriod'], 'upstream_source'=>$row['upstreamSource'],
                    'source_url'=>self::CATALOG_URL, 'source_license'=>self::LICENSE, 'accessed_at'=>self::ACCESSED_AT,
                    'checksum_sha256'=>$expectedSha256, 'mapping_method'=>self::MAPPING_METHOD,
                    'mapping_notes'=>'28000 explicitement autorisé; autobus moyen, agglomération >250 000 habitants, France continentale. Applicabilité démographique vérifiée: Tours Métropole Val de Loire (EPCI 243700754), 301 339 habitants en 2023, INSEE https://www.insee.fr/fr/statistiques/1405599?geo=EPCI-243700754 (consulté le 2026-09-24). Le total Elément inclut carburant et fabrication: périmètre life_cycle. Tours Métropole est mappée FR-TM comme moyenne de classe démographique, jamais comme mesure Fil Bleu; aucune occupation inventée. Période source avr-22 conservée textuellement, jamais transformée en date de fin.',
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
        while (($values = fgetcsv($stream, 1_000_000, ';', '"', '')) !== false) {
            if ($values === [null] || $values === []) continue;
            if (count($values) !== count($headers)) throw new \InvalidArgumentException('Malformed ADEME CSV row.');
            $row = array_combine($headers, $values);
            $external = trim($row["Identifiant de l'élément"]);
            if (!in_array($external, self::EXPECTED_IDS, true) || trim($row['Type Ligne']) !== 'Elément') continue;
            $candidate = $this->map($row, $external);
            if (isset($selected[$external]) && $selected[$external] !== $candidate) throw new \InvalidArgumentException('Contradictory duplicate ADEME identifier: '.$external.'.');
            $selected[$external] = $candidate;
        }
        fclose($stream);
        $missing = array_diff(self::EXPECTED_IDS, array_keys($selected));
        if ($missing !== []) throw new \InvalidArgumentException('Expected ADEME identifier missing: '.implode(', ', $missing).'.');
        return array_values($selected);
    }

    /** @param array<string,string> $row @return array<string,string|float> */
    private function map(array $row, string $external): array
    {
        $requiredValues = ["Type de l'élément"=>"Facteur d'émission", "Statut de l'élément"=>'Valide générique', 'Structure'=>'élément décomposé par poste', 'Nom base français'=>'Autobus moyen', 'Nom attribut français'=>'Agglomération de plus de 250 000 habitants', 'Unité français'=>'kgCO2e/passager.km', 'Localisation géographique'=>'France continentale'];
        foreach ($requiredValues as $column=>$expected) {
            if (trim($row[$column]) !== $expected) throw new \InvalidArgumentException('Unsupported ADEME mapping for '.$external.' ('.$column.').');
        }
        $raw = trim($row['Total poste non décomposé']);
        if (!preg_match('/^(0|[1-9]\d*),\d+$/D', $raw)) throw new \InvalidArgumentException('Invalid French decimal ADEME value for '.$external.'.');
        $value = (float) str_replace(',', '.', $raw);
        if ($value < 0 || !is_finite($value)) throw new \InvalidArgumentException('Invalid ADEME factor value.');
        foreach (['Date de création','Date de modification'] as $column) {
            $date = \DateTimeImmutable::createFromFormat('!d/m/Y', trim($row[$column]));
            if (!$date || $date->format('d/m/Y') !== trim($row[$column])) throw new \InvalidArgumentException('Invalid ADEME date: '.$column.'.');
        }
        if (trim($row['Source']) === '' || trim($row['Période de validité']) === '') throw new \InvalidArgumentException('ADEME source and source period are required.');
        return ['externalId'=>$external,'value'=>$value,'sourceValue'=>$raw,'sourceUnit'=>trim($row['Unité français']),'sourceStatus'=>trim($row["Statut de l'élément"]),'sourceName'=>trim($row['Nom base français']),'sourceAttribute'=>trim($row['Nom attribut français']),'sourceGeography'=>trim($row['Localisation géographique']),'sourcePeriod'=>trim($row['Période de validité']),'upstreamSource'=>trim($row['Source'])];
    }
}
