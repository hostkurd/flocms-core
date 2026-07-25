<?php

namespace FloCMS\Core;

/**
 * @deprecated since Flo Core 2.0. New API controllers should extend
 *             FloCMS\Api\Controller from hostkurd/flocms-api.
 */
abstract class ApiController extends Controller
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);

        Api::cors();
        Api::handleOptions();
    }

    protected function json(mixed $data = null, int $status = 200, array $meta = []): void
    {
        Api::json($data, $status, $meta);
    }

    protected function error(string $message, int $status = 400, array $errors = []): void
    {
        Api::error($message, $status, $errors);
    }

    protected function input(): array
    {
        return Api::input();
    }

    protected function query(string $key, mixed $default = null): mixed
    {
        return Api::query($key, $default);
    }

    protected function currentLang(): string
    {
        return Api::currentLang();
    }

    protected function bearerToken(): ?string
    {
        return Api::bearerToken();
    }

    protected function hasBearerToken(): bool
    {
        return Api::hasBearerToken();
    }

    protected function page(): int
    {
        return Api::page();
    }

    protected function limit(int $default, int $max): int
    {
        return Api::limit($default, $max);
    }

    protected function idFromParams(int $index = 0, string $message = 'Valid ID is required.'): int
    {
        $id = (int) ($this->params[$index] ?? 0);

        if ($id <= 0) {
            $this->error($message, 422);
        }

        return $id;
    }

    protected function guestTokenFromRequest(bool $createIfMissing = true): ?string
    {
        return Api::guestTokenFromRequest($createIfMissing);
    }
}
