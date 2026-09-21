<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Demo\DemoPlaceCatalog;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class CapabilitiesController
{
    #[Route('/api/v1/capabilities', name: 'capabilities', methods: ['GET'])]
    public function __invoke(DemoPlaceCatalog $catalog): JsonResponse
    {
        return new JsonResponse($catalog->capabilities());
    }
}
