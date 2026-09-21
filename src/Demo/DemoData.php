<?php

declare(strict_types=1);

namespace App\Demo;

final class DemoData
{
    public const SOURCE_ID = 'demo-journey-scenarios';

    /** @return array<string, mixed> */
    public static function provenance(): array
    {
        return ['status' => 'demo', 'sourceIds' => [self::SOURCE_ID], 'asOf' => null, 'note' => 'Scénario synthétique hors ligne; aucun horaire ni offre réelle.'];
    }

    /** @return array<string, mixed> */
    public static function source(): array
    {
        return ['id' => self::SOURCE_ID, 'publisher' => 'Ecotrip — scénarios synthétiques', 'url' => null, 'license' => null, 'accessedAt' => null, 'version' => 'journey-demo-v1', 'reuseNotes' => 'Démonstration et tests uniquement; distances, durées et émissions fictives.', 'dataStatus' => 'demo'];
    }
}
