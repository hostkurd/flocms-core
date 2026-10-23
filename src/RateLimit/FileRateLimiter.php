<?php
declare(strict_types=1);

namespace FloCMS\Core\RateLimit;

use RuntimeException;

/** Fixed-window rate limits kept in JSON files, one per key (core 2.8; was FloCMS\Api\RateLimit\FileRateLimiter). Works on any host. */
final class FileRateLimiter implements RateLimiterInterface
{
    /**
     * @param int $gcProbability run gc() on roughly 1 in $gcProbability hits (0 disables)
     * @param int $gcMaxAge seconds after the last hit before a state file is removed;
     *        keep it longer than your longest window
     */
    public function __construct(
        private readonly string $directory,
        private readonly int $gcProbability = 100,
        private readonly int $gcMaxAge = 86400
    ) {
    }

    /**
     * Delete state files not hit for $maxAge seconds. Returns how many were removed.
     */
    public function gc(?int $maxAge = null): int
    {
        $maxAge ??= $this->gcMaxAge;
        if (!is_dir($this->directory)) {
            return 0;
        }

        $cutoff = time() - max(1, $maxAge);
        $removed = 0;
        foreach (glob(rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (!preg_match('/^[0-9a-f]{64}\.json$/', basename($file))) {
                continue;
            }
            $modified = @filemtime($file);
            if ($modified !== false && $modified < $cutoff && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    public function hit(string $key, int $maxAttempts, int $windowSeconds): RateLimitResult
    {
        if ($maxAttempts < 1 || $windowSeconds < 1) {
            throw new RuntimeException('Rate-limit values must be positive.');
        }

        $this->ensureDirectory();
        if ($this->gcProbability > 0 && random_int(1, $this->gcProbability) === 1) {
            $this->gc();
        }
        $path = $this->path($key);
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open rate-limit state.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock rate-limit state.');
            }

            rewind($handle);
            $contents = stream_get_contents($handle);
            $payload = is_string($contents) && $contents !== ''
                ? json_decode($contents, true)
                : null;
            $now = time();
            $startsAt = is_array($payload) ? (int) ($payload['starts_at'] ?? $now) : $now;
            $attempts = is_array($payload) ? (int) ($payload['attempts'] ?? 0) : 0;

            if ($startsAt > $now || ($now - $startsAt) >= $windowSeconds) {
                $startsAt = $now;
                $attempts = 0;
            }

            $attempts++;
            $resetAt = $startsAt + $windowSeconds;
            $encoded = json_encode([
                'starts_at' => $startsAt,
                'attempts' => $attempts,
            ], JSON_THROW_ON_ERROR);

            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $encoded) === false || !fflush($handle)) {
                throw new RuntimeException('Unable to persist rate-limit state.');
            }

            return new RateLimitResult(
                $attempts <= $maxAttempts,
                $maxAttempts,
                max(0, $maxAttempts - $attempts),
                $resetAt,
                max(0, $resetAt - $now)
            );
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function clear(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Unable to clear rate-limit state.');
        }
    }

    private function ensureDirectory(): void
    {
        if (
            !is_dir($this->directory)
            && !mkdir($this->directory, 0755, true)
            && !is_dir($this->directory)
        ) {
            throw new RuntimeException('Unable to create rate-limit directory.');
        }
    }

    private function path(string $key): string
    {
        return rtrim($this->directory, '/\\')
            . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }
}
