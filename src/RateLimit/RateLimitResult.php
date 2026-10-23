<?php
declare(strict_types=1);

namespace FloCMS\Core\RateLimit;

/** The outcome of one hit on a rate limiter (core 2.8). */
final class RateLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $limit,
        public readonly int $remaining,
        public readonly int $resetAt,
        public readonly int $retryAfter
    ) {
    }
}
