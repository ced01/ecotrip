<?php

declare(strict_types=1);

$context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
$body = @file_get_contents('http://127.0.0.1:8000/health', false, $context);
if ($body === false) {
    exit(1);
}
try {
    $health = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    exit(1);
}
exit(($health['status'] ?? null) === 'ok' ? 0 : 1);
