<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Environmental\AdemeEmissionFactorImporter;
use App\Environmental\EmissionEstimator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class MethodologyController
{
    #[Route('/api/v1/methodology', name: 'methodology', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'version' => EmissionEstimator::METHODOLOGY_VERSION,
            'status' => 'demo',
            'indicator' => 'kgCO2e',
            'scopes' => ['operation', 'life_cycle'],
            'rules' => [
                'Calcul séparé pour chaque étape à partir de sa distance.',
                'Facteur passager-km : distance × facteur par voyageur.',
                'Une distance ou émission inconnue vaut null, jamais zéro.',
                'Un facteur ADEME n’est sélectionné que par mapping explicite, période effective et import complet.',
                'Zéro ou plusieurs facteurs applicables rendent l’émission indisponible; aucun fallback démo.',
                'Seules des estimations complètes de mêmes unité, périmètre, version et méthode sont comparables.',
            ],
            'limits' => [
                'Le mode public reste demo; ADEME doit être importé puis activé explicitement.',
                'La distance Fil Bleu reste inconnue : ses émissions restent indisponibles même avec un facteur importé.',
                'Le facteur 28000 est une moyenne de réseau urbain, pas une mesure d’un véhicule ou trajet Fil Bleu.',
                'Les sommes partielles ne permettent aucun classement carbone.',
            ],
            'sources' => [[
                'id' => 'synthetic-tests', 'publisher' => 'Ecotrip', 'url' => null, 'license' => null, 'accessedAt' => null,
                'version' => EmissionEstimator::METHODOLOGY_VERSION, 'reuseNotes' => 'Facteurs arithmétiques fictifs réservés au mode démo.', 'dataStatus' => 'demo',
            ], [
                'id' => AdemeEmissionFactorImporter::SOURCE_ID, 'publisher' => 'ADEME', 'url' => AdemeEmissionFactorImporter::CATALOG_URL,
                'license' => AdemeEmissionFactorImporter::LICENSE, 'accessedAt' => AdemeEmissionFactorImporter::ACCESSED_AT,
                'version' => 'V23.6', 'reuseNotes' => 'Export local épinglé; identifiant 28000 seulement; activation explicite, sans appel réseau.', 'dataStatus' => 'verified',
            ]],
        ]);
    }
}
