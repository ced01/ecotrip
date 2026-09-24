<?php

declare(strict_types=1);

namespace App\Journey;

use App\Place\FilBleuGtfsImporter;
use App\Provider\JourneyProvider;
use App\Provider\JourneyProviderMetadata;
use App\Provider\PlaceReferenceResolver;
use App\Provider\ProviderUnavailable;
use App\Trip\DirectionResult;
use App\Trip\DirectionStatus;
use App\Trip\JourneyQuery;

final readonly class FilBleuJourneyProvider implements JourneyProvider
{
    public function __construct(private PlaceReferenceResolver $resolver, private JourneyScheduleRepository $repository) {}

    public function metadata(): JourneyProviderMetadata
    {
        return new JourneyProviderMetadata('real', [$this->repository->source()], ['Horaires théoriques du snapshot local Fil Bleu; ni temps réel, prix, réservation, train, ni calcul carbone.']);
    }

    public function search(JourneyQuery $query): DirectionResult
    {
        $origin=$this->externalId($query->originId);
        $destination=$this->externalId($query->destinationId);
        if ($origin===null || $destination===null) return new DirectionResult(DirectionStatus::OutOfCoverage,[],['Un lieu ne possède pas de référence Fil Bleu active.']);
        if (!in_array('public_transport',$query->modes,true)) return new DirectionResult(DirectionStatus::Empty,[],['Fil Bleu couvre uniquement le mode public_transport.']);

        $rows=$this->repository->direct($origin,$destination,$query->date);
        if ($rows===[]) return new DirectionResult(DirectionStatus::Empty,[],['Aucun service direct Fil Bleu pour cette date et cette paire.']);
        $mappedOrigin=$this->internalId($origin);
        $mappedDestination=$this->internalId($destination);
        if ($mappedOrigin!==$query->originId || $mappedDestination!==$query->destinationId) return new DirectionResult(DirectionStatus::Partial,[],['Références de stations incohérentes; aucun trajet exposé.']);
        $source=$this->repository->source();
        $provenance=['status'=>'verified','sourceIds'=>[FilBleuGtfsImporter::SOURCE_ID],'asOf'=>$source['accessedAt'].'T00:00:00+02:00','note'=>'Horaire théorique GTFS Fil Bleu, version '.$source['version'].', Licence Ouverte 2.0.'];
        $itineraries=[];
        foreach($rows as $row){
            if($row['arrivalSeconds']<$row['departureSeconds']){ return new DirectionResult(DirectionStatus::Partial,$itineraries,['Horaire importé incohérent; résultats valides seulement.']); }
            $departure=$this->at($query->date,$row['departureSeconds']); $arrival=$this->at($query->date,$row['arrivalSeconds']);
            $token=substr(hash('sha256',$source['version']."\0".$row['trip']."\0".$origin."\0".$destination."\0".$row['departureSequence']."\0".$row['arrivalSequence']),0,40);
            $legId='filbleu-leg-'.$token;
            $duration=(int)ceil(($row['arrivalSeconds']-$row['departureSeconds'])/60);
            $itineraries[]=['id'=>'filbleu-trip-'.$token,'direction'=>'outbound','requestedDate'=>$query->date->format('Y-m-d'),'dataStatus'=>'real','legs'=>[[
                'id'=>$legId,'mode'=>'public_transport','subtype'=>$row['routeName']!==''?$row['routeName']:null,'originId'=>$query->originId,'destinationId'=>$query->destinationId,'durationMinutes'=>$duration,'waitingMinutes'=>0,
                'distance'=>['km'=>null,'method'=>'unknown','provenance'=>$provenance],
                'schedule'=>['departureAt'=>$departure->format(DATE_ATOM),'arrivalAt'=>$arrival->format(DATE_ATOM),'provenance'=>$provenance],'provenance'=>$provenance,
            ]],'durationMinutes'=>$duration,'transfers'=>0,'emissions'=>[
                'status'=>'unavailable','kgCO2ePerTraveler'=>null,'kgCO2eGroup'=>null,'coveredDistanceKm'=>0.0,'totalDistanceKm'=>null,'comparable'=>false,'comparisonKey'=>null,'methodologyVersion'=>\App\Environmental\EmissionEstimator::METHODOLOGY_VERSION,'assumptions'=>[],'legs'=>[['legId'=>$legId,'status'=>'unavailable','kgCO2ePerTraveler'=>null,'kgCO2eGroup'=>null,'factorId'=>null,'reason'=>'Distance Fil Bleu inconnue; aucun calcul de facteur n’est possible.']],'factors'=>[],
            ],'provenance'=>$provenance,'warnings'=>['Distance et émissions indisponibles; aucun calcul synthétique.']];
        }
        return new DirectionResult(DirectionStatus::Complete,$itineraries);
    }

    private function at(\DateTimeImmutable $serviceDate,int $seconds): \DateTimeImmutable
    {
        $timezone=new \DateTimeZone('Europe/Paris');
        $anchor=$serviceDate->setTimezone($timezone)->setTime(12,0)->sub(new \DateInterval('PT12H'));

        return (new \DateTimeImmutable('@'.($anchor->getTimestamp()+$seconds)))->setTimezone($timezone);
    }

    private function externalId(string $placeId): ?string
    {
        try { return $this->resolver->toExternalId($placeId,FilBleuGtfsImporter::PROVIDER_KEY); }
        catch (\Throwable $error) { throw new ProviderUnavailable('Fil Bleu place reference storage is unavailable.',0,$error); }
    }

    private function internalId(string $externalId): ?string
    {
        try { return $this->resolver->toInternalId(FilBleuGtfsImporter::PROVIDER_KEY,$externalId); }
        catch (\Throwable $error) { throw new ProviderUnavailable('Fil Bleu place reference storage is unavailable.',0,$error); }
    }
}
