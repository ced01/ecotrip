<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\ApiProblemException;
use App\Http\JourneySearchQuota;
use App\Provider\ProviderUnavailable;
use App\Trip\JourneyRequestValidator;
use App\Trip\JourneySearch;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class JourneySearchController
{
    #[Route('/api/v1/journeys/search', name: 'journey_search', methods: ['POST'])]
    public function __invoke(Request $request, JourneyRequestValidator $validator, JourneySearch $search, JourneySearchQuota $quota): JsonResponse
    {
        if (!str_starts_with(strtolower((string) $request->headers->get('content-type')), 'application/json')) {
            throw new ApiProblemException(415, 'unsupported_media_type');
        }
        $content = $request->getContent();
        if (strlen($content) > 16384) {
            throw new ApiProblemException(413, 'payload_too_large');
        }
        try {
            $input = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblemException(400, 'invalid_json');
        }
        $quota->consume($request->getClientIp() ?? 'unknown');
        $validated = $validator->validate($input, new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')));
        try {
            return new JsonResponse($search->search($validated));
        } catch (ProviderUnavailable) {
            throw new ApiProblemException(503, 'provider_unavailable');
        }
    }
}
