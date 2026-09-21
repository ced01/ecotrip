<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Demo\DemoPlaceCatalog;
use App\Http\ApiProblemException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class PlacesController
{
    #[Route('/api/v1/places', name: 'places', methods: ['GET'])]
    public function __invoke(Request $request, DemoPlaceCatalog $catalog): JsonResponse
    {
        $q = $request->query->get('q', '');
        $limit = filter_var($request->query->get('limit', 20), FILTER_VALIDATE_INT);
        $offset = filter_var($request->query->get('offset', 0), FILTER_VALIDATE_INT);
        $violations = [];
        if (!is_string($q) || mb_strlen($q) > 100) $violations[] = ['path' => 'q', 'message' => 'Texte de 100 caractères maximum requis.'];
        if ($limit === false || $limit < 1 || $limit > 50) $violations[] = ['path' => 'limit', 'message' => 'Entier entre 1 et 50 requis.'];
        if ($offset === false || $offset < 0 || $offset > 10000) $violations[] = ['path' => 'offset', 'message' => 'Entier entre 0 et 10000 requis.'];
        if ($violations !== []) throw new ApiProblemException(422, 'validation_failed', $violations);
        return new JsonResponse($catalog->search($q, $limit, $offset));
    }
}
