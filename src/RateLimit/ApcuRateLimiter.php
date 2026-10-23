<?php
declare(strict_types=1);

namespace FloCMS\Core\RateLimit;

use RuntimeException;

/**
 * Fixed-window rate limiter in APCu shared memory. Faster than files, but
 * only usable where the host enables the apcu extension; check isAvailable()
 * and fall back to FileRateLimiter otherwise.
 *
 * Counters live per PHP-FPM pool, so on hosts that run several pools for one
 * site the limit applies per pool.
 */
final class ApcuRateLimiter implements RateLimiterInterface
{
    public function __construct(private readonly string $prefix = 'flo-api-rl:')
    {
        if (!self::isAvailable()) {
            throw new RuntimeException('APCu is not available.');
        }
    }

    public static function isAvailable(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    public function hit(string $key, int $maxAttempts, int $windowSeconds): RateLimitResult
    {
        if ($maxAttempts < 1 || $windowSeconds < 1) {
            throw new RuntimeException('Rate-limit values must be positive.');
        }

        $now = time();
        $window = intdiv($now, $windowSeconds);
        $resetAt = ($window + 1) * $windowSeconds;
        $cacheKey = $this->prefix . hash('sha256', $key) . ':' . $windowSeconds . ':' . $window;

        apcu_add($cacheKey, 0, $windowSeconds + 1);
        $attempts = apcu_inc($cacheKey);
        if ($attempts === false) {
            // Evicted between add and inc: start the window again.
            apcu_store($cacheKey, 1, $windowSeconds + 1);
            $attempts = 1;
        }

        return new RateLimitResult(
            $attempts <= $maxAttempts,
            $maxAttempts,
            max(0, $maxAttempts - $attempts),
            $resetAt,
            max(0, $resetAt - $now)
        );
    }

    public function clear(string $key): void
    {
        $pattern = '/^' . preg_quote($this->prefix . hash('sha256', $key) . ':', '/') . '/';
        if (class_exists(\APCUIterator::class)) {
            apcu_delete(new \APCUIterator($pattern));
        }
    }
}
