<?php
declare(strict_types=1);

namespace FloCMS\Core\Tests\Unit;

use FloCMS\Core\Security\ClientIpResolver;
use FloCMS\Core\Security\IpMatcher;
use FloCMS\Core\Http\Request;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Moved from flocms-api in core 2.8. */
final class ClientIpResolverTest extends TestCase
{
    /** @param array<string, string> $headers */
    private static function request(array $headers, string $remote): Request
    {
        $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'REMOTE_ADDR' => $remote];
        foreach ($headers as $name => $value) { $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value; }
        return new Request([], [], $server, [], [], '');
    }


    /** @return iterable<string, array{0: list<string>, 1: string, 2: array<string, string>, 3: string}> */
    public static function cases(): iterable
    {
        yield 'no proxies: forwarded header ignored' => [[], '198.51.100.1', ['X-Forwarded-For' => '1.2.3.4'], '198.51.100.1'];
        yield 'untrusted remote cannot spoof' => [['10.0.0.0/8'], '198.51.100.1', ['X-Forwarded-For' => '1.2.3.4'], '198.51.100.1'];
        yield 'trusted proxy chain, rightmost untrusted' => [['10.0.0.0/8'], '10.0.0.2', ['X-Forwarded-For' => '203.0.113.66, 198.51.100.9, 10.0.0.1'], '198.51.100.9'];
        yield 'invalid forwarded entries skipped' => [['10.0.0.0/8'], '10.0.0.2', ['X-Forwarded-For' => 'garbage, 198.51.100.9'], '198.51.100.9'];
        yield 'all trusted falls back to X-Real-IP' => [['10.0.0.0/8'], '10.0.0.2', ['X-Forwarded-For' => '10.1.1.1', 'X-Real-IP' => '198.51.100.5'], '198.51.100.5'];
        yield 'cloudflare header' => [['10.0.0.0/8'], '10.0.0.2', ['CF-Connecting-IP' => '198.51.100.6'], '198.51.100.6'];
        yield 'exact proxy ip' => [['192.0.2.10'], '192.0.2.10', ['X-Forwarded-For' => '198.51.100.7'], '198.51.100.7'];
        yield 'ipv6 proxy range' => [['2001:db8::/32'], '2001:db8::1', ['X-Forwarded-For' => '2001:470::5'], '2001:470::5'];
        yield 'ipv6 client direct' => [[], '2001:db8::abcd', [], '2001:db8::abcd'];
        yield 'ipv6 not trusted for ipv4 range' => [['10.0.0.0/8'], '::1', ['X-Forwarded-For' => '1.2.3.4'], '::1'];
        yield 'invalid remote' => [[], 'nonsense', [], 'unknown'];
    }

    /**
     * @param list<string> $proxies
     * @param array<string, string> $headers
     */
    /** @dataProvider cases */
    #[DataProvider('cases')]
    public function testResolve(array $proxies, string $remote, array $headers, string $expected): void
    {
        self::assertSame($expected, (new ClientIpResolver($proxies))->resolve(self::request($headers, $remote)));
    }

    public function testInvalidProxyConfiguration(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ClientIpResolver(['10.0.0.0/33']);
    }

    public function testCidrEdges(): void
    {
        self::assertTrue(IpMatcher::matches('10.255.255.255', '10.0.0.0/8'));
        self::assertFalse(IpMatcher::matches('11.0.0.0', '10.0.0.0/8'));
        self::assertTrue(IpMatcher::matches('192.168.1.130', '192.168.1.128/25'));
        self::assertFalse(IpMatcher::matches('192.168.1.127', '192.168.1.128/25'));
        self::assertTrue(IpMatcher::matches('1.2.3.4', '0.0.0.0/0'));
        self::assertTrue(IpMatcher::matches('::1', '0:0:0:0:0:0:0:1'));
        self::assertFalse(IpMatcher::matches('not-an-ip', '10.0.0.0/8'));
    }
}
