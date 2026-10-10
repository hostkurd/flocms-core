<?php
declare(strict_types=1);

namespace FloCMS\Core\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Connection settings for tests that need a real MySQL/MariaDB server.
 *
 * Set FLO_TEST_MYSQL_HOST (and optionally _PORT, _USER, _PASS, _NAME) to run them;
 * without it they are skipped.
 */
final class MySqlConfig
{
    /** @return array{host: string, port: int, user: string, pass: string, name: string} */
    public static function require(TestCase $test): array
    {
        $host = getenv('FLO_TEST_MYSQL_HOST');

        if ($host === false || $host === '') {
            $test->markTestSkipped('Set FLO_TEST_MYSQL_HOST to run MySQL/MariaDB tests.');
        }

        return [
            'host' => $host,
            'port' => (int) (getenv('FLO_TEST_MYSQL_PORT') ?: 3306),
            'user' => (string) (getenv('FLO_TEST_MYSQL_USER') ?: 'root'),
            'pass' => (string) (getenv('FLO_TEST_MYSQL_PASS') ?: ''),
            'name' => (string) (getenv('FLO_TEST_MYSQL_NAME') ?: 'flocms_test'),
        ];
    }
}
