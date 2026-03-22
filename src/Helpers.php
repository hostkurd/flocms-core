<?php
// lib/helpers.php

use FloCMS\Core\Lang;
use FloCMS\Core\Template;

if (!function_exists('__')) {
    function __(string $key, $replace = [], ?string $default = null): string
    {
        // If second param is string → treat it as default
        if (!is_array($replace)) {
            $default = $replace;
            $replace = [];
        }

        try {
            return Lang::get($key, $replace, $default);
        } catch (\Throwable $e) {
            return $default ?? $key;
        }
    }
}

if (!function_exists('render_static_page')) {

    function render_static_page(array $data): void
    {
        $template    = (string)($data['template'] ?? '');
        $vars        = (array)($data['vars'] ?? []);
        $status      = $data['status'] ?? null;          // can be null
        $contentType = (string)($data['contentType'] ?? 'text/html; charset=UTF-8');

        if (!headers_sent()) {
            if ($status !== null) {
                http_response_code((int)$status);
            }
            header('Content-Type: ' . $contentType);
        }

        $html = ($template !== '' && is_file($template))
            ? (string)file_get_contents($template)
            : '<h1>Page</h1>';

        foreach ($vars as $key => $value) {
            $safe = htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html = str_replace('{{' . $key . '}}', $safe, $html);
        }

        echo $html;
        exit;
    }
}

if (!function_exists('template_asset')) {
    function template_asset(string $path): string {
        return Template::asset($path);
    }
}

if (!function_exists('template_partial')) {
    function template_partial(string $file): string
    {
        return Template::getPartialPath($file);
    }
}

if (!function_exists('render_partial')) {
    function render_partial(string $file, array $data = []): string
    {
        $path = Template::getPartialPath($file);

        if (!is_file($path)) {
            throw new RuntimeException("Partial not found: {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Unable to read partial: {$path}");
        }

        $compiled = \FloCMS\Core\TemplateEngine::Decode($raw);

        extract($data, EXTR_SKIP);

        ob_start();
        eval('?>' . $compiled);
        return (string) ob_get_clean();
    }
}