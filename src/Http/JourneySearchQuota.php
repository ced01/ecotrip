<?php

declare(strict_types=1);

namespace App\Http;

final class JourneySearchQuota
{
    /** @var array<string, array{started:int,count:int}> */
    private array $buckets = [];

    public function consume(string $client): void
    {
        $now = time();
        $bucket = $this->buckets[$client] ?? ['started' => $now, 'count' => 0];
        if ($now - $bucket['started'] >= 60) {
            $bucket = ['started' => $now, 'count' => 0];
        }
        if ($bucket['count'] >= 20) {
            throw new ApiProblemException(429, 'rate_limited', headers: ['Retry-After' => '60']);
        }
        ++$bucket['count'];
        $this->buckets[$client] = $bucket;
    }
}
