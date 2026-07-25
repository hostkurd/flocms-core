<?php

namespace FloCMS\Core;

use InvalidArgumentException;

class Template
{
    public static function getActiveTemplate(): string
    {
        $template = defined('ACTIVE_TEMPLATE') ? ACTIVE_TEMPLATE : 'default';
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $template) ?: 'default';
    }

    public static function getPath(string $type, string $file): string
    {
        $template = self::getActiveTemplate();
        $type = self::relativePath($type, false);
        $file = self::relativePath($file, false);

        $path = TEMPLATES_PATH . DS . $template . DS
            . str_replace('/', DS, $type . '/' . $file);
        if (file_exists($path)) {
            return $path;
        }

        $defaultPath = TEMPLATES_PATH . DS . 'default' . DS
            . str_replace('/', DS, $type . '/' . $file);
        if (file_exists($defaultPath)) {
            return $defaultPath;
        }

        throw new \RuntimeException("Template file not found: {$file}");
    }

    public static function getLayoutPath(string $layout): string
    {
        return self::getPath('layouts', $layout . '.html');
    }

    public static function getErrorPath(string $file): string
    {
        return self::getPath('errors', $file);
    }

    public static function getOfflinePath(): string
    {
        $template = self::getActiveTemplate();

        $path = TEMPLATES_PATH . DS . $template . DS . 'offline.html';
        if (file_exists($path)) {
            return $path;
        }

        $defaultPath = TEMPLATES_PATH . DS . 'default' . DS . 'offline.html';
        if (file_exists($defaultPath)) {
            return $defaultPath;
        }

        throw new \RuntimeException('Offline template not found.');
    }

    public static function asset(string $path): string
    {
        $template = self::getActiveTemplate();
        $path = self::relativePath($path, true);

        $fullPath = ROOT . DS . 'public' . DS . 'themes' . DS . $template . DS . str_replace('/', DS, $path);
        if (file_exists($fullPath)) {
            return SITE_URI . '/themes/' . $template . '/' . $path;
        }

        $defaultFullPath = ROOT . DS . 'public' . DS . 'themes' . DS . 'default' . DS . str_replace('/', DS, $path);
        if (file_exists($defaultFullPath)) {
            return SITE_URI . '/themes/default/' . $path;
        }

        return SITE_URI . '/themes/default/' . $path;
    }

    public static function getPartialPath(string $file): string
    {
        $file = self::relativePath($file, false);

        if (!str_ends_with($file, '.html')) {
            $file .= '.html';
        }

        return self::getPath('partials', $file);
    }

    private static function relativePath(string $path, bool $allowEmpty): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = trim($path, '/');

        if ($allowEmpty && $path === '') {
            return '';
        }

        if (
            $path === ''
            || str_contains($path, "\0")
            || preg_match('/^[A-Za-z]:/', $path)
        ) {
            throw new InvalidArgumentException('Invalid template-relative path.');
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || str_contains($segment, ':')
            ) {
                throw new InvalidArgumentException('Invalid template-relative path.');
            }
        }

        return implode('/', $segments);
    }
}
