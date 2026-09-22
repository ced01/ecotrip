<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\ApiProblemException;
use App\Http\JourneySearchQuota;
use App\Provider\ProviderUnavailable;
use App\Trip\JourneyRequestValidator;
use App\Trip\JourneySearch;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class JourneySearchController
{
    public function __construct(private readonly ClockInterface $clock) {}

    #[Route('/api/v1/journeys/search', name: 'journey_search', methods: ['POST'])]
    public function __invoke(Request $request, JourneyRequestValidator $validator, JourneySearch $search, JourneySearchQuota $quota): JsonResponse
    {
        if (!$this->isJsonMediaType((string) $request->headers->get('content-type'))) {
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
        $today = $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Paris'));
        $validated = $validator->validate($input, \DateTimeImmutable::createFromInterface($today));
        try {
            return new JsonResponse($search->search($validated));
        } catch (ProviderUnavailable) {
            throw new ApiProblemException(503, 'provider_unavailable');
        }
    }

    private function isJsonMediaType(string $contentType): bool
    {
        return preg_match('/^\s*application\/json\s*(?:;\s*[A-Za-z0-9!#$%&\'*+.^_`|~-]+\s*=\s*(?:"[^"\r\n]*"|[A-Za-z0-9!#$%&\'*+.^_`|~-]+)\s*)*$/iD', $contentType) === 1;
    }
}
