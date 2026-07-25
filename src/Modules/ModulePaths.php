<?php
declare(strict_types=1);

namespace FloCMS\Core\Modules;

use InvalidArgumentException;

final class ModulePaths
{
    /**
     * @param list<string> $moduleDirectories
     */
    public function __construct(
        public readonly string $root,
        public readonly array $moduleDirectories,
        public readonly string $registryCache,
        public readonly string $stateCache
    ) {
        if ($moduleDirectories === []) {
            throw new InvalidArgumentException('At least one module directory must be configured.');
        }
    }

    public static function fromRoot(
        string $root,
        string $modules = 'modules',
        string $cache = 'storage/cache'
    ): self {
        $root = self::normalizeAbsolute($root);
        $cacheDirectory = self::join($root, $cache);

        return new self(
            $root,
            [self::join($root, $modules)],
            self::join($cacheDirectory, 'modules.php'),
            self::join($cacheDirectory, 'modules-state.json')
        );
    }

    /** @param list<string> $moduleDirectories */
    public static function custom(
        string $root,
        array $moduleDirectories,
        string $registryCache,
        string $stateCache
    ): self {
        $root = self::normalizeAbsolute($root);

        return new self(
            $root,
            array_values(array_map(
                static fn (string $path): string => self::normalizeAbsolute($path, $root),
                $moduleDirectories
            )),
            self::normalizeAbsolute($registryCache, $root),
            self::normalizeAbsolute($stateCache, $root)
        );
    }

    public function relativeToRoot(string $path): ?string
    {
        $path = self::normalizeAbsolute($path);
        $prefix = rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (!str_starts_with($path, $prefix)) {
            return null;
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($prefix)));
    }

    public function resolveFromRoot(string $path): string
    {
        return self::normalizeAbsolute($path, $this->root);
    }

    private static function join(string $base, string $path): string
    {
        if (self::isAbsolute($path)) {
            return self::normalizeAbsolute($path);
        }

        return self::normalizeAbsolute(rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $path);
    }

    private static function normalizeAbsolute(string $path, ?string $base = null): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new InvalidArgumentException('Configured paths cannot be empty.');
        }

        if (!self::isAbsolute($path)) {
            if ($base === null) {
                throw new InvalidArgumentException('Expected an absolute path: ' . $path);
            }
            $path = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $path;
        }

        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $prefix = str_starts_with($path, DIRECTORY_SEPARATOR) ? DIRECTORY_SEPARATOR : substr($path, 0, 3);
        $segments = preg_split('#[\\\\/]+#', $path) ?: [];
        $normalized = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($normalized);
                continue;
            }
            $normalized[] = $segment;
        }

        if (DIRECTORY_SEPARATOR === '\\' && preg_match('/^[A-Za-z]:\\\\?$/', $prefix)) {
            return rtrim($prefix, '\\') . '\\' . implode('\\', array_slice($normalized, 1));
        }

        return DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $normalized);
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }
}
