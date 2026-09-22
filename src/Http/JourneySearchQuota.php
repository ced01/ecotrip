<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class JourneySearchQuota
{
    public function __construct(
        private readonly RateLimiterFactory $limiter,
        private readonly ClockInterface $clock,
    ) {}

    public function consume(string $client): void
    {
        $limit = $this->limiter->create($client)->consume();
        if ($limit->isAccepted()) {
            return;
        }

        $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp());
        throw new ApiProblemException(429, 'rate_limited', headers: ['Retry-After' => (string) $retryAfter]);
    }
}
