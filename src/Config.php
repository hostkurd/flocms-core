<?php
declare(strict_types=1);

namespace FloCMS\Core;

use PDO;

class Config
{

    /** @var array<string, mixed> */
    public static array $settings = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, self::$settings) ? self::$settings[$key] : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::$settings[$key] = $value;
    }

    public static function getSetting(string $key, mixed $default = false): mixed
    {
        try {
            $db = App::db();
            if (!$db) {
                return $default;
            }

            $sql = "SELECT value FROM settings WHERE param = :param AND lang = :lang LIMIT 1";
            $row = $db->query($sql, [
                'param' => $key,
                'lang' => defined('ACTIVE_LANG')
                    ? (string) ACTIVE_LANG
                    : (string) self::get('default_language', 'en'),
            ])->fetch(PDO::FETCH_ASSOC);

            return is_array($row) && array_key_exists('value', $row)
                ? strip_tags((string) $row['value'], '<br><ul><li>')
                : $default;

        } catch (\Throwable) {
            return $default;
        }
    }
}
