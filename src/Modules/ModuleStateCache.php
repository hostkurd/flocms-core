<?php
declare(strict_types=1);

namespace FloCMS\Core\Modules;

use RuntimeException;

final class ModuleStateCache
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * @return array{infrastructure_ready: bool, modules: array<string, array<string, mixed>>}|null
     */
    public function read(string $registryFingerprint): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }

        $contents = file_get_contents($this->path);
        if ($contents === false) {
            return null;
        }

        try {
            $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (
            !is_array($payload)
            || !hash_equals($registryFingerprint, (string) ($payload['registry_fingerprint'] ?? ''))
            || !is_array($payload['modules'] ?? null)
        ) {
            return null;
        }

        return [
            'infrastructure_ready' => (bool) ($payload['infrastructure_ready'] ?? false),
            'modules' => $payload['modules'],
        ];
    }

    /** @param array<string, array<string, mixed>> $states */
    public function write(
        string $registryFingerprint,
        bool $infrastructureReady,
        array $states
    ): void {
        $payload = json_encode([
            'schema' => 1,
            'generated_at' => gmdate('c'),
            'registry_fingerprint' => $registryFingerprint,
            'infrastructure_ready' => $infrastructureReady,
            'modules' => $states,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create module state cache directory: ' . $directory);
        }

        try {
            $suffix = bin2hex(random_bytes(8));
        } catch (\Throwable) {
            $suffix = str_replace('.', '', uniqid('', true));
        }
        $temporary = $this->path . '.' . $suffix . '.tmp';
        if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write module state cache.');
        }
        if (!rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to publish module state cache.');
        }
    }

    public function clear(): void
    {
        if (is_file($this->path) && !unlink($this->path)) {
            throw new RuntimeException('Unable to clear module state cache.');
        }
    }
}
