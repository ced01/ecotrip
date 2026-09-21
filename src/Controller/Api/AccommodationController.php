<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Accommodation\AccommodationQuery;
use App\Provider\AccommodationProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AccommodationController
{
    public function __construct(private readonly AccommodationProvider $provider)
    {
    }

    #[Route('/api/v1/accommodations', name: 'api_accommodations', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $parameters = $request->query->all();
        $violations = [];

        $destinationId = $parameters['destinationId'] ?? null;
        if (!is_string($destinationId) || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $destinationId) !== 1) {
            $violations[] = ['path' => 'destinationId', 'message' => 'Identifiant de destination requis et invalide.'];
        }

        [$bicycleParking, $bicycleState] = $this->featureFilter($parameters, 'bicycleParking', $violations);
        [$publicTransportNearby, $publicTransportState] = $this->featureFilter($parameters, 'publicTransportNearby', $violations);
        $limit = $this->integer($parameters, 'limit', 20, 1, 50, $violations);
        $offset = $this->integer($parameters, 'offset', 0, 0, 10000, $violations);

        if ($violations !== []) {
            return $this->validationProblem($request, $violations);
        }

        $result = $this->provider->search(new AccommodationQuery(
            $destinationId,
            $bicycleParking,
            $publicTransportNearby,
            $limit,
            $offset,
            $bicycleState,
            $publicTransportState,
        ));

        return new JsonResponse($result);
    }

    /**
     * @param array<string, mixed> $parameters
     * @param list<array{path: string, message: string}> $violations
     * @return array{?bool, ?string}
     */
    private function featureFilter(array $parameters, string $name, array &$violations): array
    {
        if (!array_key_exists($name, $parameters)) {
            return [null, null];
        }
        $value = $parameters[$name];
        if (!is_string($value) || !in_array($value, ['true', 'false', 'unknown'], true)) {
            $violations[] = ['path' => $name, 'message' => 'Valeur attendue: true, false ou unknown.'];
            return [null, null];
        }
        if ($value === 'unknown') {
            return [null, 'unknown'];
        }

        return [$value === 'true', 'known'];
    }

    /**
     * @param array<string, mixed> $parameters
     * @param list<array{path: string, message: string}> $violations
     */
    private function integer(array $parameters, string $name, int $default, int $minimum, int $maximum, array &$violations): int
    {
        if (!array_key_exists($name, $parameters)) {
            return $default;
        }
        $value = $parameters[$name];
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1 || (int) $value < $minimum || (int) $value > $maximum) {
            $violations[] = ['path' => $name, 'message' => sprintf('Entier attendu entre %d et %d.', $minimum, $maximum)];
            return $default;
        }

        return (int) $value;
    }

    /** @param list<array{path: string, message: string}> $violations */
    private function validationProblem(Request $request, array $violations): JsonResponse
    {
        return new JsonResponse([
            'type' => 'about:blank',
            'title' => 'Unprocessable Content',
            'status' => 422,
            'detail' => 'Les paramètres du catalogue sont invalides.',
            'instance' => $request->getPathInfo(),
            'code' => 'validation_failed',
            'violations' => $violations,
        ], 422, ['Content-Type' => 'application/problem+json']);
    }
}
