<?php

declare(strict_types=1);

namespace App\Provider;

use App\Accommodation\Accommodation;
use App\Accommodation\AccommodationQuery;
use App\Accommodation\EnvironmentalEvidence;

/** Offline-only synthetic adapter. It never exposes inventory or booking capabilities. */
final class DemoAccommodationProvider implements AccommodationProvider
{
    private const SOURCE_ID = 'demo-accommodations';

    public function __construct(private readonly ?\DateTimeImmutable $today = null)
    {
    }

    public function search(AccommodationQuery $query): array
    {
        if ($query->destinationId !== 'demo-lyon') {
            return $this->response('out_of_coverage', [], $query, 0, [], [
                'Destination hors couverture de la fixture synthétique.',
            ]);
        }

        $items = array_values(array_filter(
            $this->catalog(),
            fn (Accommodation $item): bool => $this->matches($item->feature('bicycleParking'), $query->bicycleParking, $query->bicycleParkingState)
                && $this->matches($item->feature('publicTransportNearby'), $query->publicTransportNearby, $query->publicTransportNearbyState),
        ));
        $total = count($items);
        $items = array_slice($items, $query->offset, $query->limit);
        $today = $this->today ?? new \DateTimeImmutable('today');
        $serialized = array_map(static fn (Accommodation $item): array => $item->toArray($today), $items);

        return $this->response($total === 0 ? 'empty' : 'complete', $serialized, $query, $total, [$this->source()], [
            'Catalogue de démonstration synthétique: aucune disponibilité ni réservation annoncée.',
            'Les caractéristiques sont distinctes des certifications; les valeurs inconnues restent null.',
        ]);
    }

    private function matches(?bool $actual, ?bool $expected, ?string $state): bool
    {
        if ($state === 'unknown') {
            return $actual === null;
        }
        if ($expected !== null) {
            return $actual === $expected;
        }

        return true;
    }

    /** @return list<Accommodation> */
    private function catalog(): array
    {
        return [
            new Accommodation(
                'demo-stay',
                'Hébergement fictif de contrat',
                'demo-lyon',
                null,
                null,
                null,
                null,
                [],
            ),
            new Accommodation(
                'demo-certified-hotel',
                'Hôtel à certification fictive documentée',
                'demo-lyon',
                false,
                true,
                180,
                [
                    'amount' => 89,
                    'currency' => 'EUR',
                    'basis' => 'night_per_room',
                    'asOf' => '2026-09-01',
                    'sourceId' => self::SOURCE_ID,
                    'dataStatus' => 'demo',
                ],
                [EnvironmentalEvidence::certification(
                    'demo-current-label',
                    'Certification environnementale fictive (démonstration uniquement)',
                    'Organisme fictif de démonstration',
                    'https://example.test/certifications/demo-current-label',
                    '2026-01-01',
                    '2099-12-31',
                    '2026-09-01',
                    self::SOURCE_ID,
                )],
            ),
            new Accommodation(
                'demo-cycle-lodge',
                'Gîte vélo fictif',
                'demo-lyon',
                true,
                false,
                1400,
                null,
                [EnvironmentalEvidence::declaration(
                    'demo-bike-declaration',
                    'Stationnement vélo déclaré dans la fixture',
                    self::SOURCE_ID,
                )],
            ),
            new Accommodation(
                'demo-free-stay',
                'Hébergement gratuit fictif',
                'demo-lyon',
                true,
                null,
                null,
                [
                    'amount' => 0,
                    'currency' => 'EUR',
                    'basis' => 'night_per_room',
                    'asOf' => '2026-09-01',
                    'sourceId' => self::SOURCE_ID,
                    'dataStatus' => 'demo',
                ],
                [EnvironmentalEvidence::certification(
                    'demo-expired-label',
                    'Ancienne certification fictive expirée',
                    'Organisme fictif de démonstration',
                    'https://example.test/certifications/demo-expired-label',
                    '2024-01-01',
                    '2025-12-31',
                    '2026-09-01',
                    self::SOURCE_ID,
                )],
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function response(string $status, array $items, AccommodationQuery $query, int $total, array $sources, array $warnings): array
    {
        return [
            'status' => $status,
            'items' => $items,
            'page' => ['limit' => $query->limit, 'offset' => $query->offset, 'total' => $total],
            'sources' => $sources,
            'warnings' => $warnings,
        ];
    }

    /** @return array<string, mixed> */
    private function source(): array
    {
        return [
            'id' => self::SOURCE_ID,
            'publisher' => 'Ecotrip — adaptateur synthétique de démonstration',
            'url' => null,
            'license' => null,
            'accessedAt' => null,
            'version' => 'task-0004-v1',
            'reuseNotes' => 'Tests et démonstration hors ligne uniquement; aucune offre ni certification réelle.',
            'dataStatus' => 'demo',
        ];
    }
}
