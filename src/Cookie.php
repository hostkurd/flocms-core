<?php
namespace FloCMS\Core;

class Cookie
{
    protected static function isHttps(): bool
    {
        return (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        );
    }

    protected static function options(int $expiryHours): array
    {
        return [
            'expires'  => time() + ($expiryHours * 3600),
            'path'     => '/',
            'domain'   => '',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax', // or 'Strict'
        ];
    }

    public static function set(string $key, string $value, int $expiryHours): void
    {
        setcookie($key, $value, self::options($expiryHours));
        $_COOKIE[$key] = $value;
    }

    public static function get(string $key): ?string
    {
        return $_COOKIE[$key] ?? null;
    }

    public static function delete(string $key): void
    {
        setcookie($key, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => '',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        unset($_COOKIE[$key]);
    }

    public static function isValid(string $key): bool
    {
        return isset($_COOKIE[$key]);
    }
}