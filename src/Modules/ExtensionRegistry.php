<?php
declare(strict_types=1);

namespace FloCMS\Core\Modules;

use RuntimeException;

final class ExtensionRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $loaded = [];

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModuleManager $manager
    ) {
    }

    /** @return array<string, mixed> */
    public function all(string $extension, bool $enabledOnly = true): array
    {
        $extension = strtolower(trim($extension));
        $cacheKey = $extension . ':' . ($enabledOnly ? 'enabled' : 'all');

        if (isset($this->loaded[$cacheKey])) {
            return $this->loaded[$cacheKey];
        }

        $entries = [];
        foreach ($this->registry->all() as $module) {
            if ($enabledOnly && !$this->manager->isEnabled($module->id)) {
                continue;
            }

            $path = $module->extensions[$extension] ?? null;
            if ($path === null) {
                continue;
            }

            $value = require $module->resolvePath($path);
            if (!is_array($value) && !is_callable($value) && !is_object($value)) {
                throw new RuntimeException(
                    'Module extension must return an array, object, or callable: '
                    . $module->id . '/' . $extension
                );
            }
            $entries[$module->id] = $value;
        }

        return $this->loaded[$cacheKey] = $entries;
    }

    public function clear(): void
    {
        $this->loaded = [];
    }
}
