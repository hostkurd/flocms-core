<?php
declare(strict_types=1);

namespace FloCMS\Core\Http;

use JsonException;
use RuntimeException;

final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    private function __construct(
        private string $body = '',
        private int $status = 200
    ) {
        if ($status < 100 || $status > 599) {
            throw new RuntimeException('Invalid HTTP status code: ' . $status);
        }
    }

    public static function make(string $body = '', int $status = 200): self
    {
        return new self($body, $status);
    }

    public static function html(string $html, int $status = 200): self
    {
        return (new self($html, $status))
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    public static function text(string $text, int $status = 200): self
    {
        return (new self($text, $status))
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }

    public static function json(mixed $data, int $status = 200): self
    {
        try {
            $body = json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $error) {
            throw new RuntimeException('Unable to encode JSON response.', 0, $error);
        }

        return (new self($body, $status))
            ->header('Content-Type', 'application/json; charset=utf-8');
    }

    public static function noContent(int $status = 204): self
    {
        return new self('', $status);
    }

    /**
     * Kept mutable for compatibility with Flo 1.x. New code may treat a
     * response as immutable after it leaves a controller.
     */
    public function header(string $name, string $value): self
    {
        if (!preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name)) {
            throw new RuntimeException('Invalid response header name.');
        }

        if (str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new RuntimeException('Response header values cannot contain line breaks.');
        }

        foreach (array_keys($this->headers) as $existingName) {
            if (strcasecmp($existingName, $name) === 0 && $existingName !== $name) {
                unset($this->headers[$existingName]);
            }
        }

        $this->headers[$name] = $value;

        return $this;
    }

    public function appendHeader(string $name, string $value): self
    {
        $existing = null;
        foreach ($this->headers as $existingName => $existingValue) {
            if (strcasecmp($existingName, $name) === 0) {
                $existing = $existingValue;
                break;
            }
        }

        return $this->header(
            $name,
            $existing === null || $existing === '' ? $value : $existing . ', ' . $value
        );
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function withoutBody(): self
    {
        $clone = clone $this;
        $clone->body = '';

        return $clone;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        if ($this->status !== 204 && $this->status !== 304) {
            echo $this->body;
        }
    }
}
