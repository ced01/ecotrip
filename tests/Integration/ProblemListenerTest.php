<?php

namespace App\Tests\Integration;

use App\Http\ProblemListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ProblemListenerTest extends TestCase
{
    #[DataProvider('errors')]
    public function testStatusHeadersAndRedaction(\Throwable $error, int $status, string $code, array $expectedHeaders): void
    {
        $event = new ExceptionEvent(self::createStub(HttpKernelInterface::class), Request::create('/api/v1/private?token=never-reflect'), HttpKernelInterface::MAIN_REQUEST, $error);
        (new ProblemListener())($event);
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame($status, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertSame($status, $data['status']);
        self::assertSame($code, $data['code']);
        self::assertSame('/api/v1/private', $data['instance']);
        self::assertStringNotContainsString('secret', $response->getContent());
        self::assertStringNotContainsString('never-reflect', $response->getContent());
        foreach ($expectedHeaders as $name => $value) {
            self::assertSame($value, $response->headers->get($name));
        }
    }

    public static function errors(): iterable
    {
        yield [new \RuntimeException('secret SQL /app/private'), 500, 'internal_error', []];
        yield [new HttpException(405, 'secret', null, ['Allow' => 'GET']), 405, 'method_not_allowed', ['Allow' => 'GET']];
        yield [new HttpException(429, 'secret', null, ['Retry-After' => '60']), 429, 'rate_limited', ['Retry-After' => '60']];
        yield [new HttpException(503, 'secret'), 503, 'provider_unavailable', []];
        yield [new HttpException(400, 'secret'), 400, 'invalid_json', []];
        yield [new HttpException(413, 'secret'), 413, 'payload_too_large', []];
        yield [new HttpException(415, 'secret'), 415, 'unsupported_media_type', []];
        yield [new HttpException(422, 'secret'), 422, 'validation_failed', []];
    }

    public function testNonApiExceptionsRemainUnderSymfonyControl(): void
    {
        $event = new ExceptionEvent(self::createStub(HttpKernelInterface::class), Request::create('/other'), HttpKernelInterface::MAIN_REQUEST, new \RuntimeException());
        (new ProblemListener())($event);
        self::assertNull($event->getResponse());
    }
}
