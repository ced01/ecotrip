<?php

namespace App\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

#[AsEventListener(event: 'kernel.exception', priority: 10)]
final class ProblemListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $error = $event->getThrowable();
        $status = $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $error->getStatusCode() : 500;
        $codes = [400 => 'invalid_json', 404 => 'not_found', 405 => 'method_not_allowed', 413 => 'payload_too_large', 415 => 'unsupported_media_type', 422 => 'validation_failed', 429 => 'rate_limited', 503 => 'provider_unavailable'];
        $headers = $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $error->getHeaders() : [];
        $headers['Content-Type'] = 'application/problem+json';
        $event->setResponse(new JsonResponse([
            'type' => 'about:blank',
            'title' => \Symfony\Component\HttpFoundation\Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => $status >= 500 ? 'Service temporairement indisponible.' : 'La requête ne peut pas être traitée.',
            'instance' => $event->getRequest()->getPathInfo(),
            'code' => $codes[$status] ?? 'internal_error',
        ], $status, $headers));
    }
}
