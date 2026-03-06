<?php
namespace FloCMS\Core;

class Session
{
    protected const FLASH_KEY = '__flash';

    public static function setFlash(string $message, string $type = 'info'): void
    {
        $_SESSION[self::FLASH_KEY] = [
            'message' => $message,
            'type'    => $type,
        ];
    }

    public static function hasFlash(): bool
    {
        return !empty($_SESSION[self::FLASH_KEY]['message']);
    }

    public static function getFlash(): ?array
    {
        if (!self::hasFlash()) {
            return null;
        }

        $flash = $_SESSION[self::FLASH_KEY];
        unset($_SESSION[self::FLASH_KEY]);

        return $flash;
    }

    public static function flash(): void
    {
        $flash = self::getFlash();
        if ($flash) {
            echo $flash['message'];
        }
    }

    public static function flashType(): void
    {
        if (!self::hasFlash()) {
            return;
        }

        echo $_SESSION[self::FLASH_KEY]['type'];
    }

    public static function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function get(string $key)
    {
        return $_SESSION[$key] ?? null;
    }

    public static function delete(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'],
                    $params['domain'],
                    $params['secure'],
                    $params['httponly']
                );
            }

            session_destroy();
        }
    }
}