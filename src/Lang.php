<?php
namespace FloCMS\Core;

class Lang{
    protected static $data;

    public static function load($lang): void
    {
        $lang = $lang ?: 'en';

        $file = ROOT . '/lang/' . $lang . '.php';
        if (!file_exists($file)) {
            $file = ROOT . '/lang/en.php'; // fallback
        }

        $data = include $file;

        // Ensure array
        self::$data = is_array($data) ? $data : [];
    }

    /**
     * Translation for $key, or $default_value when missing.
     *
     * Placeholders are replaced from $replace: Lang::get('welcome', '', ['name' => 'Sara'])
     * turns "Welcome, :name" into "Welcome, Sara". ':Name' and ':NAME' get the
     * value capitalised / upper-cased. Values are not HTML-escaped.
     */
    public static function get($key, $default_value = '', array $replace = [])
    {
        $value = $default_value;

        if (is_array(self::$data)) {
            $value = self::$data[strtolower((string) $key)] ?? $default_value;
        }

        if ($replace !== [] && is_string($value)) {
            $value = self::replace($value, $replace);
        }

        return $value;
    }

    /**
     * Replace :placeholders; longer names first so :user does not break :username.
     */
    public static function replace(string $line, array $replace): string
    {
        uksort($replace, static fn ($a, $b) => strlen((string) $b) <=> strlen((string) $a));

        $pairs = [];
        foreach ($replace as $name => $value) {
            $name = (string) $name;
            $value = (string) $value;
            $pairs[':' . $name] = $value;
            $pairs[':' . ucfirst($name)] = ucfirst($value);
            $pairs[':' . strtoupper($name)] = strtoupper($value);
        }

        return strtr($line, $pairs);
    }

    public static function isRTL(): bool
    {
        // if language not loaded yet, default LTR
        if (!is_array(self::$data)) {
            return false;
        }

        $dir = self::$data['lng.dir'] ?? 'ltr';
        return strtolower($dir) === 'rtl';
    }
}