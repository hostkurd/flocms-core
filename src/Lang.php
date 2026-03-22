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

    public static function get(string $key, array $replace = [], ?string $default = null): string
    {
        if (!is_array(self::$data)) {
            return $default ?? $key;
        }

        $key = strtolower($key);
        $value = self::$data[$key] ?? ($default ?? $key);

        foreach ($replace as $search => $replacement) {
            $value = str_replace(':' . $search, (string) $replacement, $value);
        }

        return $value;
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