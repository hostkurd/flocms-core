<?php
declare(strict_types=1);

namespace FloCMS\Core\RateLimit;

/** A fixed-window rate limiter (core 2.8; was FloCMS\Api\RateLimit\RateLimiterInterface). */
interface RateLimiterInterface
{
    public function hit(string $key, int $maxAttempts, int $windowSeconds): RateLimitResult;

    public function clear(string $key): void;
}
