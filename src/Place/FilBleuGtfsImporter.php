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
    private const REQUIRED = ['stops.txt'=>10_000_000, 'feed_infos.txt'=>100_000, 'routes.txt'=>10_000_000, 'calendar.txt'=>10_000_000, 'calendar_dates.txt'=>10_000_000, 'trips.txt'=>20_000_000, 'stop_times.txt'=>150_000_000];

    public function __construct(private Connection $connection) {}

    public function import(string $path, string $version, ?string $expectedSha256): GtfsImportResult
    {
        if ($version === '' || mb_strlen($version) > 100) throw new \InvalidArgumentException('A non-empty source version of at most 100 characters is required.');
        if (!is_string($expectedSha256) || !preg_match('/^[0-9a-f]{64}$/', $expectedSha256)) throw new \InvalidArgumentException('An expected lowercase SHA-256 checksum is required.');
        if (!is_file($path) || !is_readable($path)) throw new \InvalidArgumentException('The local GTFS ZIP is not readable.');
        $actual = hash_file('sha256', $path);
        if (!hash_equals($expectedSha256, $actual)) throw new \InvalidArgumentException('GTFS checksum mismatch.');

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) throw new \InvalidArgumentException('Invalid GTFS ZIP.');
        try {
            foreach (self::REQUIRED as $name => $maximum) {
                $stat = $zip->statName($name);
                if ($stat === false || ($stat['size'] ?? 0) <= 0 || ($stat['size'] ?? 0) > $maximum) throw new \InvalidArgumentException('GTFS ZIP is incomplete or a required member is too large: '.$name);
            }
            $feedRows = $this->csvAll($zip, 'feed_infos.txt');
            if (count($feedRows) !== 1 || trim($feedRows[0]['feed_version'] ?? '') !== $version) throw new \InvalidArgumentException('Declared version does not match the unique feed_version.');
            $feedStart = $this->date(trim($feedRows[0]['feed_start_date'] ?? ''));
            $feedEnd = $this->date(trim($feedRows[0]['feed_end_date'] ?? ''));
            if ($feedEnd < $feedStart) throw new \InvalidArgumentException('Invalid feed validity range.');

            $stops = $this->stops($this->csvAll($zip, 'stops.txt'));
            $routes = $this->keyed($this->csvAll($zip, 'routes.txt'), 'route_id');
            $services = $this->keyed($this->csvAll($zip, 'calendar.txt'), 'service_id');
            $exceptions = $this->csvAll($zip, 'calendar_dates.txt');
            foreach ($exceptions as $exception) {
                $serviceId = trim($exception['service_id'] ?? '');
                if ($serviceId !== '' && !isset($services[$serviceId])) {
                    $services[$serviceId] = ['service_id'=>$serviceId, 'monday'=>'0','tuesday'=>'0','wednesday'=>'0','thursday'=>'0','friday'=>'0','saturday'=>'0','sunday'=>'0','start_date'=>str_replace('-','',$feedStart),'end_date'=>str_replace('-','',$feedEnd)];
                }
            }
            $trips = $this->keyed($this->csvAll($zip, 'trips.txt'), 'trip_id');
            if ($routes === [] || $services === [] || $trips === []) throw new \InvalidArgumentException('GTFS schedule tables must not be empty.');
            $stationCount = count(array_filter($stops, static fn(array $r): bool => $r['locationType'] === 1));

            return $this->connection->transactional(function(Connection $db) use ($zip, $version, $expectedSha256, $feedStart, $feedEnd, $stops, $routes, $services, $exceptions, $trips, $stationCount): GtfsImportResult {
                $db->executeStatement(<<<'SQL'
INSERT INTO data_source (id,publisher,url,license,accessed_at,version,reuse_notes,data_status)
VALUES (:id,:publisher,:url,:license,:accessed,:version,:notes,'verified')
ON CONFLICT (id) DO UPDATE SET publisher=EXCLUDED.publisher,url=EXCLUDED.url,license=EXCLUDED.license,accessed_at=EXCLUDED.accessed_at,version=EXCLUDED.version,reuse_notes=EXCLUDED.reuse_notes,data_status=EXCLUDED.data_status
SQL, ['id'=>self::SOURCE_ID,'publisher'=>'Tours Métropole Val de Loire / Fil Bleu (Tours)','url'=>self::CATALOG_URL,'license'=>'Licence Ouverte 2.0','accessed'=>'2026-09-23','version'=>$version,'notes'=>'Snapshot GTFS local vérifié; horaires théoriques, sans temps réel. Artefact: '.self::DOWNLOAD_URL]);
                $importId = (int)$db->fetchOne(<<<'SQL'
INSERT INTO place_import (source_id,provider_key,feed_version,checksum_sha256,station_count,reference_count,feed_start_date,feed_end_date,journey_ready)
VALUES (:source,:provider,:version,:checksum,:stations,:references,:start,:end,FALSE) RETURNING id
SQL, ['source'=>self::SOURCE_ID,'provider'=>self::PROVIDER_KEY,'version'=>$version,'checksum'=>$expectedSha256,'stations'=>$stationCount,'references'=>count($stops),'start'=>$feedStart,'end'=>$feedEnd]);
                $this->persistPlaces($db, $stops, $importId);
                $this->persistSchedules($db, $zip, $routes, $services, $exceptions, $trips, $stops, $importId);
                $db->executeStatement('UPDATE place_import SET journey_ready=TRUE WHERE id=:id', ['id'=>$importId]);
                return new GtfsImportResult($stationCount, count($stops), $importId, $version, $expectedSha256);
            });
        } finally { $zip->close(); }
    }

    public static function internalId(string $externalId): string { return 'filbleu-'.substr(hash('sha256', self::SOURCE_ID."\0".$externalId), 0, 40); }

    /** @param array<string,array{name:string,latitude:float,longitude:float,timezone:string,locationType:int,parent:string}> $rows */
    private function persistPlaces(Connection $db, array $rows, int $importId): void
    {
        $db->executeStatement('UPDATE place_external_reference SET active=FALSE WHERE provider_key=:provider', ['provider'=>self::PROVIDER_KEY]);
        foreach ($rows as $externalId=>$row) {
            $placeId = $row['locationType'] === 1 ? self::internalId($externalId) : null;
            $existing = $db->fetchAssociative('SELECT place_id,location_type FROM place_external_reference WHERE provider_key=:provider AND external_id=:external', ['provider'=>self::PROVIDER_KEY,'external'=>$externalId]);
            if ($existing !== false && (($existing['place_id'] ?? null) !== $placeId || (int)$existing['location_type'] !== $row['locationType'])) throw new \InvalidArgumentException('External reference was reassigned.');
            if ($placeId !== null) {
                $owner = $db->fetchOne('SELECT source_id FROM place WHERE id=:id', ['id'=>$placeId]);
                if ($owner !== false && $owner !== self::SOURCE_ID) throw new \InvalidArgumentException('Deterministic internal ID collision.');
                $db->executeStatement("INSERT INTO place (id,name,country_code,latitude,longitude,timezone,source_id,data_status) VALUES (:id,:name,'FR',:lat,:lon,:tz,:source,'verified') ON CONFLICT (id) DO UPDATE SET name=EXCLUDED.name,country_code=EXCLUDED.country_code,latitude=EXCLUDED.latitude,longitude=EXCLUDED.longitude,timezone=EXCLUDED.timezone,source_id=EXCLUDED.source_id,data_status=EXCLUDED.data_status", ['id'=>$placeId,'name'=>$row['name'],'lat'=>$row['latitude'],'lon'=>$row['longitude'],'tz'=>$row['timezone'],'source'=>self::SOURCE_ID]);
            }
            $db->executeStatement('INSERT INTO place_external_reference (provider_key,external_id,place_id,parent_external_id,location_type,active,first_seen_import_id,last_seen_import_id) VALUES (:provider,:external,:place,:parent,:type,TRUE,:import,:import) ON CONFLICT (provider_key,external_id) DO UPDATE SET parent_external_id=EXCLUDED.parent_external_id,active=TRUE,last_seen_import_id=EXCLUDED.last_seen_import_id', ['provider'=>self::PROVIDER_KEY,'external'=>$externalId,'place'=>$placeId,'parent'=>$row['parent'] ?: null,'type'=>$row['locationType'],'import'=>$importId]);
        }
    }

    /** @param array<string,array<string,string>> $routes @param array<string,array<string,string>> $services @param list<array<string,string>> $exceptions @param array<string,array<string,string>> $trips @param array<string,mixed> $stops */
    private function persistSchedules(Connection $db, \ZipArchive $zip, array $routes, array $services, array $exceptions, array $trips, array $stops, int $importId): void
    {
        foreach ($routes as $id=>$r) {
            $type=$this->integer($r['route_type'] ?? '', 0, 999, 'route_type');
            $db->insert('gtfs_route',['import_id'=>$importId,'external_id'=>$id,'short_name'=>mb_substr(trim($r['route_short_name'] ?? ''),0,100),'long_name'=>mb_substr(trim($r['route_long_name'] ?? ''),0,255),'route_type'=>$type]);
        }
        foreach ($services as $id=>$s) {
            $values=[]; foreach (['monday','tuesday','wednesday','thursday','friday','saturday','sunday'] as $day) $values[$day]=$this->integer($s[$day] ?? '',0,1,$day) === 1 ? 'true' : 'false';
            $db->insert('gtfs_service',['import_id'=>$importId,'external_id'=>$id,...$values,'start_date'=>$this->date($s['start_date'] ?? ''),'end_date'=>$this->date($s['end_date'] ?? '')]);
        }
        $seenExceptions=[];
        foreach ($exceptions as $e) {
            $sid=trim($e['service_id'] ?? ''); $date=$this->date($e['date'] ?? ''); $key=$sid."\0".$date;
            if (!isset($services[$sid]) || isset($seenExceptions[$key])) throw new \InvalidArgumentException('Invalid or duplicate service exception.');
            $seenExceptions[$key]=true;
            $db->insert('gtfs_service_exception',['import_id'=>$importId,'service_id'=>$sid,'service_date'=>$date,'exception_type'=>$this->integer($e['exception_type'] ?? '',1,2,'exception_type')]);
        }
        foreach ($trips as $id=>$t) {
            $route=trim($t['route_id'] ?? ''); $service=trim($t['service_id'] ?? '');
            if (!isset($routes[$route]) || !isset($services[$service])) throw new \InvalidArgumentException('Trip references an unknown route or service.');
            $db->insert('gtfs_trip',['import_id'=>$importId,'external_id'=>$id,'route_id'=>$route,'service_id'=>$service,'headsign'=>mb_substr(trim($t['trip_headsign'] ?? ''),0,255)]);
        }
        $count=[]; $batch=[];
        foreach ($this->csvStream($zip,'stop_times.txt') as $r) {
            $trip=trim($r['trip_id'] ?? ''); $stop=trim($r['stop_id'] ?? '');
            if (!isset($trips[$trip]) || !isset($stops[$stop])) throw new \InvalidArgumentException('Stop time references an unknown trip or stop.');
            $arrival=$this->time($r['arrival_time'] ?? ''); $departure=$this->time($r['departure_time'] ?? '');
            if ($departure < $arrival) throw new \InvalidArgumentException('Departure precedes arrival in stop_times.txt.');
            $batch[]=['import_id'=>$importId,'trip_id'=>$trip,'stop_id'=>$stop,'stop_sequence'=>$this->integer($r['stop_sequence'] ?? '',0,2147483647,'stop_sequence'),'arrival_seconds'=>$arrival,'departure_seconds'=>$departure,'pickup_type'=>$this->optionalType($r['pickup_type'] ?? ''),'drop_off_type'=>$this->optionalType($r['drop_off_type'] ?? '')];
            $count[$trip]=($count[$trip]??0)+1;
            if (count($batch)>=500) { $this->insertStopTimes($db,$batch); $batch=[]; }
        }
        if ($batch!==[]) $this->insertStopTimes($db,$batch);
        foreach ($trips as $id=>$_) if (($count[$id]??0)<2) throw new \InvalidArgumentException('Every trip must contain at least two stop times.');
    }

    /** @param list<array<string,int|string>> $rows */
    private function insertStopTimes(Connection $db,array $rows): void
    {
        $columns=array_keys($rows[0]); $params=[]; $groups=[];
        foreach ($rows as $i=>$row) { $marks=[]; foreach ($columns as $c) { $key=$c.$i; $marks[]=':'.$key; $params[$key]=$row[$c]; } $groups[]='('.implode(',',$marks).')'; }
        $db->executeStatement('INSERT INTO gtfs_stop_time ('.implode(',',$columns).') VALUES '.implode(',',$groups),$params);
    }

    /** @param list<array<string,string>> $input @return array<string,array{name:string,latitude:float,longitude:float,timezone:string,locationType:int,parent:string}> */
    private function stops(array $input): array
    {
        $rows=[];
        foreach ($input as $s) {
            $id=trim($s['stop_id']??''); $name=trim($s['stop_name']??''); $type=filter_var($s['location_type']??null,FILTER_VALIDATE_INT); $lat=filter_var($s['stop_lat']??null,FILTER_VALIDATE_FLOAT); $lon=filter_var($s['stop_lon']??null,FILTER_VALIDATE_FLOAT); $tz=trim($s['stop_timezone']??'')?:'Europe/Paris';
            if ($id===''||mb_strlen($id)>255||$name===''||mb_strlen($name)>255||!in_array($type,[0,1],true)||$lat===false||$lat< -90||$lat>90||$lon===false||$lon< -180||$lon>180||$tz!=='Europe/Paris') throw new \InvalidArgumentException('Invalid mandatory GTFS stop data.');
            $candidate=['name'=>$name,'latitude'=>(float)$lat,'longitude'=>(float)$lon,'timezone'=>$tz,'locationType'=>$type,'parent'=>trim($s['parent_station']??'')];
            if (isset($rows[$id])) throw new \InvalidArgumentException('Duplicate stop_id.'); $rows[$id]=$candidate;
        }
        if ($rows===[]) throw new \InvalidArgumentException('No GTFS stops found.');
        foreach ($rows as $r) if ($r['locationType']===0 && $r['parent']!=='' && (!isset($rows[$r['parent']])||$rows[$r['parent']]['locationType']!==1)) throw new \InvalidArgumentException('Physical stop references an unknown commercial station.');
        return $rows;
    }

    /** @param list<array<string,string>> $rows @return array<string,array<string,string>> */
    private function keyed(array $rows,string $column): array { $out=[]; foreach($rows as $r){$id=trim($r[$column]??''); if($id===''||mb_strlen($id)>255||isset($out[$id])) throw new \InvalidArgumentException('Missing or duplicate '.$column.'.'); $out[$id]=$r;} return $out; }
    private function integer(string $v,int $min,int $max,string $name): int { if(!preg_match('/^\d+$/D',$v)||($n=(int)$v)<$min||$n>$max) throw new \InvalidArgumentException('Invalid '.$name.'.'); return $n; }
    private function optionalType(string $v): int { return $v===''?0:$this->integer($v,0,3,'pickup/drop-off type'); }
    private function date(string $v): string { if(!preg_match('/^(\d{4})(\d{2})(\d{2})$/D',trim($v),$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1])) throw new \InvalidArgumentException('Invalid GTFS date.'); return "$m[1]-$m[2]-$m[3]"; }
    private function time(string $v): int { if(!preg_match('/^(\d{1,2}):([0-5]\d):([0-5]\d)$/D',trim($v),$m)||($h=(int)$m[1])>167) throw new \InvalidArgumentException('Invalid GTFS time.'); return $h*3600+(int)$m[2]*60+(int)$m[3]; }

    /** @return list<array<string,string>> */
    private function csvAll(\ZipArchive $zip,string $name): array { return iterator_to_array($this->csvStream($zip,$name),false); }
    /** @return \Generator<int,array<string,string>> */
    private function csvStream(\ZipArchive $zip,string $name): \Generator
    {
        $stream=$zip->getStream($name); if($stream===false) throw new \InvalidArgumentException('Cannot read '.$name);
        try { $headers=fgetcsv($stream,1_000_000,',','"',''); if(!is_array($headers)||$headers===[]||count($headers)!==count(array_unique($headers))) throw new \InvalidArgumentException('Invalid CSV header in '.$name); $headers=array_map(static fn($h)=>ltrim((string)$h,"\xEF\xBB\xBF"),$headers);
            while(($values=fgetcsv($stream,1_000_000,',','"',''))!==false){ if($values===[null]||$values===[])continue; if(count($values)!==count($headers))throw new \InvalidArgumentException('Malformed CSV row in '.$name); yield array_combine($headers,$values); }
        } finally { fclose($stream); }
    }
}
