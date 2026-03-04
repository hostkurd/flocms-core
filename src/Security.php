<?php
namespace FloCMS\Core;

class Security{
    public static function secureText($text){
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}