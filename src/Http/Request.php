<?php
declare(strict_types=1);

namespace FloCMS\Core\Http;

use JsonException;

final class Request
{
    /** @var array<string, string> */
    private array $headers;

    /** @var array<string, mixed> */
    private array $attributes = [];

    /** @var array<string, string> */
    private array $routeParams = [];

    private ?array $decodedJson = null;
    private bool $jsonDecoded = false;
    private ?JsonException $jsonError = null;

    /**
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     * @param array<string, mixed> $files
     * @param array<string, mixed> $cookie
     * @param array<string, string>|null $headers
     */
    public function __construct(
        private array $get,
        private array $post,
        private array $server,
        private array $files,
        private array $cookie,
        private string $rawBody = '',
        ?array $headers = null
    ) {
        $this->headers = $headers ?? self::headersFromServer($server);
    }

    public static function fromGlobals(?string $rawBody = null): self
    {
        return new self(
            $_GET,
            $_POST,
            $_SERVER,
            $_FILES,
            $_COOKIE,
            $rawBody ?? (string) file_get_contents('php://input')
        );
    }

    public function method(): string
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function isMethod(string ...$methods): bool
    {
        $method = $this->method();

        return in_array($method, array_map('strtoupper', $methods), true);
    }

    public function isStateChanging(): bool
    {
        return $this->isMethod('POST', 'PUT', 'PATCH', 'DELETE');
    }

    public function uri(): string
    {
        return (string) ($this->server['REQUEST_URI'] ?? '/');
    }

    public function path(): string
    {
        $path = parse_url($this->uri(), PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $normalized = preg_replace('#/+#', '/', '/' . trim($path, '/'));

        return is_string($normalized) && $normalized !== '' ? $normalized : '/';
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function contentType(): string
    {
        return strtolower(trim(explode(';', (string) $this->header('Content-Type', ''))[0]));
    }

    public function acceptsJson(): bool
    {
        $accept = strtolower((string) $this->header('Accept', ''));

        return str_contains($accept, 'application/json') || str_contains($accept, '+json');
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if (!$this->jsonDecoded) {
            $this->jsonDecoded = true;

            if (trim($this->rawBody) === '') {
                $this->decodedJson = [];
            } else {
                try {
                    $decoded = json_decode($this->rawBody, true, 512, JSON_THROW_ON_ERROR);
                    $this->decodedJson = is_array($decoded) ? $decoded : [];
                } catch (JsonException $error) {
                    $this->decodedJson = [];
                    $this->jsonError = $error;
                }
            }
        }

        return $this->decodedJson ?? [];
    }

    public function jsonError(): ?JsonException
    {
        $this->json();

        return $this->jsonError;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $input = $this->all();

        return array_key_exists($key, $input) ? $input[$key] : $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $json = ($this->contentType() === 'application/json' || str_ends_with($this->contentType(), '+json'))
            ? $this->json()
            : [];

        return array_merge($this->get, $this->post, $json);
    }

    /** @return array<string, mixed> */
    public function query(): array
    {
        return $this->get;
    }

    public function queryValue(string $key, mixed $default = null): mixed
    {
        return $this->get[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function files(): array
    {
        return $this->files;
    }

    /** @return array<string, mixed>|null */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) ? $file : null;
    }

    /** @return array<string, mixed> */
    public function cookies(): array
    {
        return $this->cookie;
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookie[$key] ?? $default;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    public function withAttribute(string $name, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;

        return $clone;
    }

    public function attribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /** @param array<string, string> $params */
    public function withRouteParams(array $params): self
    {
        $clone = clone $this;
        $clone->routeParams = $params;

        return $clone;
    }

    public function route(string $name, ?string $default = null): ?string
    {
        return $this->routeParams[$name] ?? $default;
    }

    /** @return array<string, string> */
    public function routeParams(): array
    {
        return $this->routeParams;
    }

    public function bearerToken(): ?string
    {
        foreach (['Authorization', 'X-FloCMS-Authorization'] as $headerName) {
            $header = trim((string) $this->header($headerName, ''));
            if ($header !== '' && strlen($header) <= 4096
                && preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
                $token = trim((string) ($matches[1] ?? ''));

                return $token !== '' ? $token : null;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $server */
    private static function headersFromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[strtolower($name)] = (string) $value;
                continue;
            }

            if (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[strtolower(str_replace('_', '-', $key))] = (string) $value;
            }
        }

        return $headers;
    }
}
