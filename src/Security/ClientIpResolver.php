<?php
declare(strict_types=1);

namespace FloCMS\Core\Security;

use FloCMS\Core\Http\Request;
use InvalidArgumentException;

/**
 * The visitor's real IP address behind trusted proxies (core 2.8; was
 * FloCMS\Api\Security\ClientIpResolver). Forwarding headers are only read
 * when the direct peer is one of the trusted proxies.
 */
final class ClientIpResolver
{
    /** @var list<string> */
    private array $trustedProxies;

    /** @param list<string> $trustedProxies IP addresses or CIDR ranges */
    public function __construct(array $trustedProxies = [])
    {
        $normalized = [];
        foreach ($trustedProxies as $proxy) {
            $proxy = trim($proxy);
            if (!$this->validProxy($proxy)) {
                throw new InvalidArgumentException('Invalid trusted proxy address or CIDR: ' . $proxy);
            }
            $normalized[] = $proxy;
        }

        $this->trustedProxies = array_values(array_unique($normalized));
    }

    public function resolve(Request $request): string
    {
        $remote = trim((string) $request->server('REMOTE_ADDR', ''));
        if (!$this->validIp($remote)) {
            return 'unknown';
        }

        if (!$this->isTrusted($remote)) {
            return $remote;
        }

        $forwarded = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $request->header('X-Forwarded-For', ''))
        ), fn (string $candidate): bool => $this->validIp($candidate)));

        for ($index = count($forwarded) - 1; $index >= 0; $index--) {
            $candidate = $forwarded[$index];
            if (!$this->isTrusted($candidate)) {
                return $candidate;
            }
        }

        $realIp = trim((string) $request->header('X-Real-IP', ''));
        if ($this->validIp($realIp)) {
            return $realIp;
        }

        $cloudflare = trim((string) $request->header('CF-Connecting-IP', ''));
        if ($this->validIp($cloudflare)) {
            return $cloudflare;
        }

        if ($forwarded !== []) {
            return $forwarded[0];
        }

        return $remote;
    }

    private function isTrusted(string $ip): bool
    {
        return IpMatcher::matchesAny($ip, $this->trustedProxies);
    }

    private function validIp(string $ip): bool
    {
        return IpMatcher::isValidIp($ip);
    }

    private function validProxy(string $proxy): bool
    {
        return IpMatcher::isValidRange($proxy);
    }
}
