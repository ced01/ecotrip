<?php

declare(strict_types=1);

namespace App\Trip;

use App\Provider\JourneyProvider;
use App\Provider\ProviderUnavailable;

final class JourneySearch
{
    public function __construct(private readonly JourneyProvider $provider) {}

    /** @param array<string, mixed> $request @return array<string, mixed> */
    public function search(array $request): array
    {
        $outbound = $this->direction(new JourneyQuery($request['originId'], $request['destinationId'], $request['departureDate'], $request['travelers'], $request['modes']));
        $inbound = $request['returnDate'] === null
            ? new DirectionResult(DirectionStatus::NotRequested, [])
            : $this->direction(new JourneyQuery($request['destinationId'], $request['originId'], $request['returnDate'], $request['travelers'], $request['modes']));

        if ($outbound->status === DirectionStatus::Unavailable
            && ($request['returnDate'] === null || $inbound->status === DirectionStatus::Unavailable)) {
            throw new ProviderUnavailable('All requested directions failed.');
        }
        $wireRequest = $request;
        $wireRequest['departureDate'] = $request['departureDate']->format('Y-m-d');
        $wireRequest['returnDate'] = $request['returnDate']?->format('Y-m-d');
        $metadata = $this->provider->metadata();
        return [
            'dataMode' => $metadata->dataMode, 'request' => $wireRequest,
            'outbound' => $this->wire($outbound), 'inbound' => $this->wire($inbound),
            'sources' => $metadata->sources,
            'warnings' => $metadata->warnings,
        ];
    }

    private function direction(JourneyQuery $query): DirectionResult
    {
        try {
            return $this->provider->search($query);
        } catch (ProviderUnavailable) {
            return new DirectionResult(DirectionStatus::Unavailable, [], ['Direction indisponible auprès du fournisseur configuré.']);
        }
    }

    /** @return array<string, mixed> */
    private function wire(DirectionResult $result): array
    {
        return ['status' => $result->status->value, 'itineraries' => $result->itineraries, 'warnings' => $result->warnings];
    }
}
