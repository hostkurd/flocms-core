<?php
declare(strict_types=1);

namespace FloCMS\Core\Tests\Unit;

use FloCMS\Core\RateLimit\FileRateLimiter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FileRateLimiterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/flo-api-frl-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '*/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->directory . '*') ?: [] as $dir) {
            rmdir($dir);
        }
    }

    public function testCountsAndResetsAfterTheWindow(): void
    {
        $limiter = new FileRateLimiter($this->directory, 0);

        $first = $limiter->hit('k', 2, 1);
        self::assertTrue($first->allowed);
        self::assertSame(1, $first->remaining);
        self::assertTrue($limiter->hit('k', 2, 1)->allowed);
        $blocked = $limiter->hit('k', 2, 1);
        self::assertFalse($blocked->allowed);
        self::assertSame(0, $blocked->remaining);
        self::assertLessThanOrEqual(1, $blocked->retryAfter);

        sleep(2);
        self::assertTrue($limiter->hit('k', 2, 1)->allowed, 'a new window starts');
    }

    public function testClearAndSeparateKeys(): void
    {
        $limiter = new FileRateLimiter($this->directory, 0);
        $limiter->hit('a', 1, 60);

        self::assertFalse($limiter->hit('a', 1, 60)->allowed);
        self::assertTrue($limiter->hit('b', 1, 60)->allowed);
        $limiter->clear('a');
        self::assertTrue($limiter->hit('a', 1, 60)->allowed);
    }

    public function testInvalidLimits(): void
    {
        $this->expectException(RuntimeException::class);

        (new FileRateLimiter($this->directory))->hit('k', 0, 60);
    }

    public function testConcurrentHitsAreCountedExactly(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is not available.');
        }

        $children = [];
        for ($i = 0; $i < 10; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $limiter = new FileRateLimiter($this->directory, 0);
                for ($j = 0; $j < 10; $j++) {
                    $limiter->hit('shared', 1000, 60);
                }
                exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $next = (new FileRateLimiter($this->directory, 0))->hit('shared', 1000, 60);
        self::assertSame(1000 - 101, $next->remaining, '100 concurrent hits plus this one');
    }
}
