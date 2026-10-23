<?php
declare(strict_types=1);

namespace FloCMS\Core\Security;

/**
 * IPv4/IPv6 address and CIDR matching (core 2.8; was FloCMS\Api\Security\IpMatcher).
 */
final class IpMatcher
{
    public static function isValidIp(string $ip): bool
    {
        return $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /** An address ("10.0.0.1", "::1") or CIDR range ("10.0.0.0/8", "2001:db8::/32"). */
    public static function isValidRange(string $range): bool
    {
        if (!str_contains($range, '/')) {
            return self::isValidIp($range);
        }

        [$network, $prefix] = explode('/', $range, 2);
        if (!self::isValidIp($network) || !ctype_digit($prefix)) {
            return false;
        }

        $binary = inet_pton($network);

        return $binary !== false && (int) $prefix <= strlen($binary) * 8;
    }

    public static function matches(string $ip, string $range): bool
    {
        if (!str_contains($range, '/')) {
            $ipBinary = @inet_pton($ip);
            $rangeBinary = @inet_pton($range);

            return $ipBinary !== false && $rangeBinary !== false && hash_equals($rangeBinary, $ipBinary);
        }

        [$network, $prefix] = explode('/', $range, 2);
        $ipBinary = @inet_pton($ip);
        $networkBinary = @inet_pton($network);
        if ($ipBinary === false || $networkBinary === false || strlen($ipBinary) !== strlen($networkBinary)) {
            return false;
        }

        $bits = (int) $prefix;
        if ($bits < 0 || $bits > strlen($ipBinary) * 8) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if ($bytes > 0 && substr($ipBinary, 0, $bytes) !== substr($networkBinary, 0, $bytes)) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($ipBinary[$bytes]) & $mask) === (ord($networkBinary[$bytes]) & $mask);
    }

    /** @param list<string> $ranges */
    public static function matchesAny(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::matches($ip, $range)) {
                return true;
            }
        }

        return false;
    }
}
