<?php

namespace FloCMS\Core;

/**
 * @deprecated since Flo Core 2.0. Install hostkurd/flocms-api and use its
 *             Kernel, Router, middleware, and response factory.
 */
class Api
{
    public static function cors(array $allowedOrigins = []): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (empty($allowedOrigins)) {
            $allowedOrigins = self::allowedOriginsFromEnvironment();
        }

        if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN, X-Guest-Token, Accept');
        header('Access-Control-Max-Age: 86400');
    }

    private static function allowedOriginsFromEnvironment(): array
    {
        $origins = [];

        foreach (['API_ALLOWED_ORIGINS', 'FRONTEND_URL', 'APP_URL'] as $key) {
            $value = '';
            if (class_exists(Env::class)) {
                $value = (string) Env::get($key, '');
            }
            $value = $value ?: (string) ($_ENV[$key] ?? getenv($key) ?: '');

            foreach (explode(',', $value) as $origin) {
                $origin = rtrim(trim($origin), '/');
                if ($origin !== '' && preg_match('#^https?://#i', $origin)) {
                    $origins[$origin] = true;
                }
            }
        }

        if (empty($origins)) {
            foreach ([
                'http://localhost:3000',
                'http://127.0.0.1:3000',
                'http://localhost',
                'http://127.0.0.1',
            ] as $origin) {
                $origins[$origin] = true;
            }
        }

        return array_keys($origins);
    }

    public static function handleOptions(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    public static function json(mixed $data = null, int $status = 200, array $meta = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        $response = [
            'success' => $status >= 200 && $status < 300,
            'data' => $data,
        ];

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $message, int $status = 400, array $errors = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        if (!self::debugEnabled()) {
            $errors = self::redactSensitiveErrors($errors, $status);
        }

        echo json_encode([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function debugEnabled(): bool
    {
        $value = class_exists(Env::class) ? Env::get('APP_DEBUG', false) : ($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?? false);
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private static function redactSensitiveErrors(array $errors, int $status): array
    {
        if ($status >= 500) {
            return [];
        }

        foreach (['error', 'database', 'exception', 'trace', 'file', 'line'] as $key) {
            unset($errors[$key]);
        }

        return $errors;
    }

    public static function input(): array
    {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw ?: '', true);

        if (is_array($json)) {
            return array_merge($_POST, $json);
        }

        return $_POST;
    }

    public static function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public static function currentLang(): string
    {
        return defined('ACTIVE_LANG') ? ACTIVE_LANG : 'en';
    }

    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if ($header === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        if (preg_match('/Bearer\s+(.*)$/i', (string) $header, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    public static function hasBearerToken(): bool
    {
        return self::bearerToken() !== null;
    }

    public static function page(int $default = 1): int
    {
        return max(1, (int) self::query('page', $default));
    }

    public static function limit(int $default = 20, int $max = 100): int
    {
        return min($max, max(1, (int) self::query('limit', $default)));
    }

    public static function guestTokenFromRequest(bool $createIfMissing = true): ?string
    {
        $token = $_SERVER['HTTP_X_GUEST_TOKEN'] ?? '';

        if ($token === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            $token = $headers['X-Guest-Token'] ?? $headers['x-guest-token'] ?? '';
        }

        if ($token === '') {
            $input = self::input();
            $token = (string) (self::query('guest_token', '') ?: ($input['guest_token'] ?? ''));
        }

        $token = trim((string) $token);

        if ($token !== '') {
            return substr($token, 0, 100);
        }

        if (!$createIfMissing) {
            return null;
        }

        return 'guest_' . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }
}
