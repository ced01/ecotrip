<?php

declare(strict_types=1);

use cebe\openapi\Reader;

require dirname(__DIR__).'/vendor/autoload.php';

// cebe/php-openapi 1.x supports PHP 8 but emits legacy signature notices on PHP 8.4.
// Keep validation output actionable without hiding warnings or errors from this script.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

$root = dirname(__DIR__);
$contractPath = $root.'/docs/openapi.yaml';
$cli = $root.'/vendor/bin/php-openapi';

$command = escapeshellarg(PHP_BINARY).' -d error_reporting=8191 '.escapeshellarg($cli).' validate --silent '.escapeshellarg($contractPath);
passthru($command, $status);
if ($status !== 0) {
    fwrite(STDERR, "OpenAPI schema validation failed.\n");
    exit($status);
}

$openApi = Reader::readFromYamlFile(realpath($contractPath));
if (!$openApi->validate()) {
    foreach ($openApi->getErrors() as $error) {
        fwrite(STDERR, "OpenAPI structural error: {$error}\n");
    }
    exit(1);
}

$document = json_decode(file_get_contents($contractPath), true, 512, JSON_THROW_ON_ERROR);
$examples = [
    'capabilities.json' => ['Capabilities', '/api/v1/capabilities', 'get'],
    'places.json' => ['PlacesResponse', '/api/v1/places', 'get'],
    'journeys.json' => ['JourneysResponse', '/api/v1/journeys/search', 'post'],
    'accommodations.json' => ['AccommodationsResponse', '/api/v1/accommodations', 'get'],
    'methodology.json' => ['Methodology', '/api/v1/methodology', 'get'],
    'problem.json' => ['Problem', null, null],
];

$errors = [];
foreach ($examples as $file => [$schemaName, $path, $method]) {
    $examplePath = $root.'/docs/examples/'.$file;
    $value = json_decode(file_get_contents($examplePath), true, 512, JSON_THROW_ON_ERROR);
    validateValue($value, $document['components']['schemas'][$schemaName], '$', $document, $errors);

    if ($path !== null) {
        $inline = $document['paths'][$path][$method]['responses']['200']['content']['application/json']['example'] ?? null;
        if ($inline !== $value) {
            $errors[] = "{$file}: checked-in example differs from the OpenAPI response example";
        }
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "Contract error: {$error}\n");
    }
    exit(1);
}

printf("Validated OpenAPI 3.0 contract and %d JSON examples.\n", count($examples));

/**
 * Validate the OpenAPI 3.0 schema keywords used by this contract.
 *
 * @param mixed $value
 * @param array<string, mixed> $schema
 * @param array<string, mixed> $document
 * @param list<string> $errors
 */
function validateValue(mixed $value, array $schema, string $path, array $document, array &$errors): void
{
    if (isset($schema['$ref'])) {
        $prefix = '#/components/schemas/';
        if (!str_starts_with($schema['$ref'], $prefix)) {
            $errors[] = "{$path}: unsupported reference {$schema['$ref']}";
            return;
        }
        $name = substr($schema['$ref'], strlen($prefix));
        if (!isset($document['components']['schemas'][$name])) {
            $errors[] = "{$path}: unresolved schema reference {$schema['$ref']}";
            return;
        }
        validateValue($value, $document['components']['schemas'][$name], $path, $document, $errors);
        return;
    }

    if ($value === null && ($schema['nullable'] ?? false) === true) {
        return;
    }

    foreach ($schema['allOf'] ?? [] as $part) {
        validateValue($value, $part, $path, $document, $errors);
    }

    if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
        $errors[] = "{$path}: value is not in the declared enum";
        return;
    }

    $type = $schema['type'] ?? null;
    $validType = match ($type) {
        'object' => is_array($value) && !array_is_list($value),
        'array' => is_array($value) && array_is_list($value),
        'string' => is_string($value),
        'integer' => is_int($value),
        'number' => is_int($value) || is_float($value),
        'boolean' => is_bool($value),
        null => true,
        default => false,
    };
    if (!$validType) {
        $errors[] = "{$path}: expected {$type}, got ".get_debug_type($value);
        return;
    }

    if ($type === 'object') {
        $properties = $schema['properties'] ?? [];
        foreach ($schema['required'] ?? [] as $required) {
            if (!array_key_exists($required, $value)) {
                $errors[] = "{$path}: missing required property {$required}";
            }
        }
        if (($schema['additionalProperties'] ?? true) === false) {
            foreach (array_diff(array_keys($value), array_keys($properties)) as $extra) {
                $errors[] = "{$path}: unexpected property {$extra}";
            }
        }
        foreach ($properties as $name => $propertySchema) {
            if (array_key_exists($name, $value)) {
                validateValue($value[$name], $propertySchema, $path.'.'.$name, $document, $errors);
            }
        }
    }

    if ($type === 'array') {
        if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
            $errors[] = "{$path}: too few items";
        }
        if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
            $errors[] = "{$path}: too many items";
        }
        if (($schema['uniqueItems'] ?? false) && count(array_unique(array_map('serialize', $value))) !== count($value)) {
            $errors[] = "{$path}: duplicate items";
        }
        foreach ($value as $index => $item) {
            validateValue($item, $schema['items'], $path.'['.$index.']', $document, $errors);
        }
    }

    if ($type === 'string') {
        if (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
            $errors[] = "{$path}: string exceeds maxLength";
        }
        if (isset($schema['pattern']) && preg_match('~'.$schema['pattern'].'~D', $value) !== 1) {
            $errors[] = "{$path}: string does not match pattern";
        }
        if (($schema['format'] ?? null) === 'date' && !isValidDate($value)) {
            $errors[] = "{$path}: invalid RFC 3339 full-date";
        }
        if (($schema['format'] ?? null) === 'date-time' && !isValidDateTime($value)) {
            $errors[] = "{$path}: invalid RFC 3339 date-time";
        }
        if (($schema['format'] ?? null) === 'uri' && filter_var($value, FILTER_VALIDATE_URL) === false) {
            $errors[] = "{$path}: invalid URI";
        }
    }

    if (is_int($value) || is_float($value)) {
        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            $errors[] = "{$path}: value is below minimum";
        }
        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            $errors[] = "{$path}: value is above maximum";
        }
        if (($schema['exclusiveMinimum'] ?? false) === true && isset($schema['minimum']) && $value <= $schema['minimum']) {
            $errors[] = "{$path}: value must be greater than minimum";
        }
    }
}

function isValidDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function isValidDateTime(string $value): bool
{
    return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) === 1
        && DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339_EXTENDED, $value) !== false;
}
