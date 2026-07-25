<?php
declare(strict_types=1);

namespace FloCMS\Core\Modules;

use FloCMS\Core\Modules\Exceptions\InvalidModuleException;

final class ModuleDefinition
{
    /**
     * @param list<ModuleDependency> $dependencies
     * @param array<string, string> $autoload
     * @param list<class-string> $providers
     * @param array<string, string> $routes
     * @param array<string, string> $extensions
     * @param list<string> $migrations
     * @param list<string> $controllers
     * @param list<string> $models
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $description,
        public readonly string $version,
        public readonly int $schemaVersion,
        public readonly ModuleKind $kind,
        public readonly bool $defaultEnabled,
        public readonly int $priority,
        public readonly array $dependencies,
        public readonly array $autoload,
        public readonly array $providers,
        public readonly array $routes,
        public readonly array $extensions,
        public readonly array $migrations,
        public readonly array $controllers,
        public readonly array $models,
        public readonly string $basePath,
        public readonly array $metadata = []
    ) {
    }

    /** @param array<string, mixed> $manifest */
    public static function fromArray(array $manifest, string $basePath): self
    {
        foreach (['id', 'name', 'version', 'kind', 'schema_version'] as $required) {
            if (!array_key_exists($required, $manifest)) {
                throw new InvalidModuleException('Module manifest is missing "' . $required . '".');
            }
        }

        $id = strtolower(trim((string) $manifest['id']));
        if (!preg_match('/^[a-z][a-z0-9-]*$/', $id)) {
            throw new InvalidModuleException('Invalid module id: ' . $id);
        }

        $name = trim((string) $manifest['name']);
        if ($name === '' || strlen($name) > 150) {
            throw new InvalidModuleException('Module name must contain 1 to 150 bytes: ' . $id);
        }

        $version = trim((string) $manifest['version']);
        if (!self::isValidVersion($version)) {
            throw new InvalidModuleException('Invalid semantic version for ' . $id . ': ' . $version);
        }

        $kind = ModuleKind::tryFrom(strtolower(trim((string) $manifest['kind'])));
        if ($kind === null) {
            throw new InvalidModuleException('Module kind must be "core" or "optional": ' . $id);
        }

        $defaultEnabled = filter_var(
            $manifest['default_enabled'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        if ($kind === ModuleKind::Core && !$defaultEnabled) {
            throw new InvalidModuleException('Core modules must be enabled by default: ' . $id);
        }

        $schemaVersion = filter_var(
            $manifest['schema_version'],
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]]
        );
        if ($schemaVersion === false) {
            throw new InvalidModuleException('Invalid schema_version for ' . $id . '.');
        }

        $dependencies = [];
        foreach (self::listValue($manifest, 'dependencies') as $dependency) {
            if (!is_array($dependency) && !is_string($dependency)) {
                throw new InvalidModuleException('Invalid dependency in module ' . $id . '.');
            }
            $dependencies[] = ModuleDependency::fromArray($dependency);
        }

        $autoload = [];
        $rawAutoload = $manifest['autoload'] ?? [];
        if (!is_array($rawAutoload)) {
            throw new InvalidModuleException('Module autoload map must be an array: ' . $id);
        }
        foreach ($rawAutoload as $prefix => $relativeDirectory) {
            $prefix = trim((string) $prefix, " \t\n\r\0\x0B\\") . '\\';
            if (!preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+$/', $prefix)) {
                throw new InvalidModuleException('Invalid PSR-4 prefix in module ' . $id . ': ' . $prefix);
            }
            $autoload[$prefix] = self::validateRelativePath((string) $relativeDirectory, $id);
        }

        $providers = [];
        foreach (self::listValue($manifest, 'providers') as $provider) {
            $provider = ltrim(trim((string) $provider), '\\');
            if (!self::isClassName($provider)) {
                throw new InvalidModuleException('Invalid provider class in module ' . $id . ': ' . $provider);
            }
            $providers[] = $provider;
        }

        return new self(
            $id,
            $name,
            trim((string) ($manifest['description'] ?? '')),
            $version,
            (int) $schemaVersion,
            $kind,
            $defaultEnabled,
            (int) ($manifest['priority'] ?? 1000),
            $dependencies,
            $autoload,
            array_values(array_unique($providers)),
            self::pathMap($manifest, 'routes', $id),
            self::pathMap($manifest, 'extensions', $id),
            self::pathList($manifest, 'migrations', $id),
            self::classList($manifest, 'controllers', $id),
            self::classList($manifest, 'models', $id),
            rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $basePath), DIRECTORY_SEPARATOR),
            is_array($manifest['metadata'] ?? null) ? $manifest['metadata'] : []
        );
    }

    public static function isValidVersion(string $version): bool
    {
        return (bool) preg_match(
            '/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)'
            . '(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?'
            . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/',
            $version
        );
    }

    public function isCore(): bool
    {
        return $this->kind === ModuleKind::Core;
    }

    /** @return list<string> */
    public function dependencyIds(): array
    {
        return array_map(
            static fn (ModuleDependency $dependency): string => $dependency->id,
            $this->dependencies
        );
    }

    public function resolvePath(string $relativePath): string
    {
        $relativePath = self::validateRelativePath($relativePath, $this->id);

        return $this->basePath . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    /** @return array<string, mixed> */
    public function toArray(?string $basePath = null): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'version' => $this->version,
            'schema_version' => $this->schemaVersion,
            'kind' => $this->kind->value,
            'default_enabled' => $this->defaultEnabled,
            'priority' => $this->priority,
            'dependencies' => array_map(
                static fn (ModuleDependency $dependency): array => $dependency->toArray(),
                $this->dependencies
            ),
            'autoload' => $this->autoload,
            'providers' => $this->providers,
            'routes' => $this->routes,
            'extensions' => $this->extensions,
            'migrations' => $this->migrations,
            'controllers' => $this->controllers,
            'models' => $this->models,
            'base_path' => $basePath ?? $this->basePath,
            'metadata' => $this->metadata,
        ];
    }

    /** @param array<string, mixed> $manifest @return list<mixed> */
    private static function listValue(array $manifest, string $key): array
    {
        $value = $manifest[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidModuleException('Module "' . $key . '" must be a list.');
        }

        return $value;
    }

    /** @param array<string, mixed> $manifest @return array<string, string> */
    private static function pathMap(array $manifest, string $key, string $id): array
    {
        $value = $manifest[$key] ?? [];
        if (!is_array($value)) {
            throw new InvalidModuleException('Module "' . $key . '" must be a map: ' . $id);
        }

        $paths = [];
        foreach ($value as $name => $path) {
            $name = strtolower(trim((string) $name));
            if (!preg_match('/^[a-z][a-z0-9_.-]*$/', $name)) {
                throw new InvalidModuleException('Invalid ' . $key . ' key in module ' . $id . '.');
            }
            $paths[$name] = self::validateRelativePath((string) $path, $id);
        }

        return $paths;
    }

    /** @param array<string, mixed> $manifest @return list<string> */
    private static function pathList(array $manifest, string $key, string $id): array
    {
        $paths = [];
        foreach (self::listValue($manifest, $key) as $path) {
            $paths[] = self::validateRelativePath((string) $path, $id);
        }

        return array_values(array_unique($paths));
    }

    /** @param array<string, mixed> $manifest @return list<string> */
    private static function classList(array $manifest, string $key, string $id): array
    {
        $classes = [];
        foreach (self::listValue($manifest, $key) as $class) {
            $class = ltrim(trim((string) $class), '\\');
            if (!self::isClassName($class) && !preg_match('/^[A-Z][A-Za-z0-9_]*$/', $class)) {
                throw new InvalidModuleException('Invalid class in ' . $key . ' for module ' . $id . '.');
            }
            $classes[] = $class;
        }

        return array_values(array_unique($classes));
    }

    private static function validateRelativePath(string $path, string $id): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = rtrim($path, '/');

        if (
            $path === ''
            || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:\//', $path)
            || in_array('..', explode('/', $path), true)
            || str_contains($path, "\0")
        ) {
            throw new InvalidModuleException('Unsafe path in module ' . $id . ': ' . $path);
        }

        return $path;
    }

    private static function isClassName(string $class): bool
    {
        return (bool) preg_match(
            '/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/',
            $class
        );
    }
}
