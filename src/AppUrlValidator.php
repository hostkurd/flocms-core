<?php

namespace FloCMS\Core;

class AppUrlValidator
{
    public static function validateBasic(string $appUrl): string
    {
        $appUrl = rtrim(trim($appUrl), '/');

        if ($appUrl === '') {
            throw new \RuntimeException('APP_URL is missing in .env.');
        }

        if (!filter_var($appUrl, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('APP_URL is invalid. Example: http://localhost/flocms');
        }

        $scheme = strtolower((string) parse_url($appUrl, PHP_URL_SCHEME));
        $host   = (string) parse_url($appUrl, PHP_URL_HOST);

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('APP_URL must start with http:// or https://.');
        }

        if ($host === '') {
            throw new \RuntimeException('APP_URL must contain a valid host.');
        }

        return $appUrl;
    }

    public static function detectStrictMismatch(string $appUrl, array $server): ?array
    {
        $appUrl = rtrim(trim($appUrl), '/');

        if ($appUrl === '' || !filter_var($appUrl, FILTER_VALIDATE_URL)) {
            return null;
        }

        $appScheme = strtolower((string) parse_url($appUrl, PHP_URL_SCHEME));
        $appHost   = strtolower((string) parse_url($appUrl, PHP_URL_HOST));
        $appPort   = (string) (parse_url($appUrl, PHP_URL_PORT) ?? '');
        $appPath   = '/' . trim((string) parse_url($appUrl, PHP_URL_PATH), '/');
        $appPath   = rtrim($appPath, '/');
        $appPath   = $appPath === '' ? '/' : $appPath;

        $isHttps = (
            (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off')
            || (isset($server['SERVER_PORT']) && (int) $server['SERVER_PORT'] === 443)
        );

        $requestScheme = $isHttps ? 'https' : 'http';
        $requestHost   = strtolower((string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? ''));
        $requestHost   = preg_replace('/:\d+$/', '', $requestHost);
        $requestPort   = (string) ($server['SERVER_PORT'] ?? '');

        $scriptDir = str_replace('\\', '/', dirname($server['SCRIPT_NAME'] ?? ''));
        $scriptDir = rtrim($scriptDir, '/');

        if (substr($scriptDir, -7) === '/public') {
            $scriptDir = substr($scriptDir, 0, -7);
        }

        $currentBasePath = $scriptDir === '' ? '/' : $scriptDir;

        if ($appScheme !== $requestScheme) {
            return [
                'type' => 'scheme',
                'message' => "APP_URL scheme mismatch. Current request uses {$requestScheme}, but APP_URL uses {$appScheme}.",
                'expected' => $requestScheme,
                'actual' => $appScheme,
            ];
        }

        if ($appHost !== $requestHost) {
            return [
                'type' => 'host',
                'message' => "APP_URL host mismatch. Current request host is {$requestHost}, but APP_URL host is {$appHost}.",
                'expected' => $requestHost,
                'actual' => $appHost,
            ];
        }

        if ($appPort !== '' && $requestPort !== '' && $appPort !== $requestPort) {
            return [
                'type' => 'port',
                'message' => "APP_URL port mismatch. Current request port is {$requestPort}, but APP_URL port is {$appPort}.",
                'expected' => $requestPort,
                'actual' => $appPort,
            ];
        }

        if ($appPath !== $currentBasePath) {
            return [
                'type' => 'path',
                'message' => "APP_URL path mismatch. Current app path is {$currentBasePath}, but APP_URL path is {$appPath}.",
                'expected' => $currentBasePath,
                'actual' => $appPath,
            ];
        }

        return null;
    }

    public static function getSuggestedUrl(array $server): string
    {
        $isHttps = (
            (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off')
            || (isset($server['SERVER_PORT']) && (int) $server['SERVER_PORT'] === 443)
        );

        $scheme = $isHttps ? 'https' : 'http';
        $host   = (string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost');
        $host   = preg_replace('/:\d+$/', '', $host);

        $port = (string) ($server['SERVER_PORT'] ?? '');
        $includePort = $port !== '' && !in_array($port, ['80', '443'], true);

        $scriptDir = str_replace('\\', '/', dirname($server['SCRIPT_NAME'] ?? ''));
        $scriptDir = rtrim($scriptDir, '/');

        if (substr($scriptDir, -7) === '/public') {
            $scriptDir = substr($scriptDir, 0, -7);
        }

        $path = $scriptDir === '' ? '' : $scriptDir;

        return $scheme . '://' . $host . ($includePort ? ':' . $port : '') . $path;
    }
}