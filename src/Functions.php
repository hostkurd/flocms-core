<?php
namespace FloCMS\Core;

class Functions{

    public static function getLangPath($lang){
        return $lang == Env::get('DEFAULT_LANG')?'':'/'.$lang;
    }

    /**
     * Escape a value for HTML output. Also available as the global e() helper.
     */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}