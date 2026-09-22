<?php

declare(strict_types=1);

namespace App\Trip;

use App\Http\ApiProblemException;
use App\Provider\PlaceProvider;

final class JourneyRequestValidator
{
    private const MODES = ['train', 'coach', 'walk', 'public_transport', 'bicycle', 'carpool', 'flight'];

    public function __construct(private readonly PlaceProvider $places) {}

    /** @param mixed $input @return array<string, mixed> */
    public function validate(mixed $input, \DateTimeImmutable $today): array
    {
        $violations = [];
        if (!is_array($input) || array_is_list($input)) {
            throw new ApiProblemException(422, 'validation_failed', [['path' => '$', 'message' => 'Un objet JSON est requis.']]);
        }
        $allowed = ['originId', 'destinationId', 'departureDate', 'returnDate', 'travelers', 'modes'];
        foreach (array_diff(array_keys($input), $allowed) as $extra) {
            $violations[] = ['path' => $extra, 'message' => 'Champ inconnu.'];
        }
        foreach (['originId', 'destinationId', 'departureDate', 'travelers', 'modes'] as $required) {
            if (!array_key_exists($required, $input)) {
                $violations[] = ['path' => $required, 'message' => 'Champ requis.'];
            }
        }
        foreach (['originId', 'destinationId'] as $field) {
            if (isset($input[$field]) && (!is_string($input[$field]) || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $input[$field]) !== 1)) {
                $violations[] = ['path' => $field, 'message' => 'Identifiant invalide.'];
            } elseif (isset($input[$field]) && !$this->places->has($input[$field])) {
                $violations[] = ['path' => $field, 'message' => 'Lieu inconnu.'];
            }
        }
        if (($input['originId'] ?? null) === ($input['destinationId'] ?? null)) {
            $violations[] = ['path' => 'destinationId', 'message' => 'La destination doit différer de l’origine.'];
        }
        if (!isset($input['travelers']) || !is_int($input['travelers']) || $input['travelers'] < 1 || $input['travelers'] > 9) {
            $violations[] = ['path' => 'travelers', 'message' => 'Valeur entière entre 1 et 9 requise.'];
        }
        if (!isset($input['modes']) || !is_array($input['modes']) || !array_is_list($input['modes']) || $input['modes'] === [] || count($input['modes']) > 7 || count(array_unique($input['modes'], SORT_REGULAR)) !== count($input['modes']) || array_any($input['modes'], static fn (mixed $mode): bool => !is_string($mode) || !in_array($mode, self::MODES, true))) {
            $violations[] = ['path' => 'modes', 'message' => 'Liste unique de modes reconnus requise.'];
        }
        $departure = $this->date($input['departureDate'] ?? null, 'departureDate', $violations);
        $return = array_key_exists('returnDate', $input) && $input['returnDate'] !== null ? $this->date($input['returnDate'], 'returnDate', $violations) : null;
        if ($departure !== null && $departure < $today->setTime(0, 0)) {
            $violations[] = ['path' => 'departureDate', 'message' => 'La date ne peut pas être passée.'];
        }
        if ($departure !== null && $return !== null && $return < $departure) {
            $violations[] = ['path' => 'returnDate', 'message' => 'Le retour doit suivre le départ.'];
        }
        if ($violations !== []) {
            throw new ApiProblemException(422, 'validation_failed', $violations);
        }
        return ['originId' => $input['originId'], 'destinationId' => $input['destinationId'], 'departureDate' => $departure, 'returnDate' => $return, 'travelers' => $input['travelers'], 'modes' => $input['modes']];
    }

    /** @param list<array{path:string,message:string}> $violations */
    private function date(mixed $value, string $path, array &$violations): ?\DateTimeImmutable
    {
        if (!is_string($value) || ($date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Paris'))) === false || $date->format('Y-m-d') !== $value) {
            $violations[] = ['path' => $path, 'message' => 'Date locale YYYY-MM-DD invalide.'];
            return null;
        }
        return $date;
    }
}
