<?php
declare(strict_types=1);

namespace FloCMS\Core\Modules;

use FloCMS\Core\Modules\Exceptions\InvalidModuleException;
use RuntimeException;

final class ModuleRegistry
{
    /** @var array<string, ModuleDefinition> */
    private array $modules;

    /** @param iterable<ModuleDefinition> $modules */
    public function __construct(iterable $modules)
    {
        $indexed = [];
        foreach ($modules as $module) {
            if (isset($indexed[$module->id])) {
                throw new InvalidModuleException('Duplicate module id: ' . $module->id);
            }
            $indexed[$module->id] = $module;
        }

        uasort(
            $indexed,
            static fn (ModuleDefinition $left, ModuleDefinition $right): int =>
                ($left->priority <=> $right->priority) ?: strcmp($left->id, $right->id)
        );

        $this->modules = $indexed;
        $this->validate();
    }

    public static function fromCache(string $cacheFile, ModulePaths $paths): self
    {
        if (!is_file($cacheFile)) {
            throw new RuntimeException(
                'Compiled module registry is missing. Run the module sync command: ' . $cacheFile
            );
        }

        $compiled = require $cacheFile;
        if (
            !is_array($compiled)
            || (int) ($compiled['schema'] ?? 0) !== 1
            || !is_array($compiled['modules'] ?? null)
        ) {
            throw new RuntimeException('Compiled module registry is invalid: ' . $cacheFile);
        }

        $definitions = [];
        foreach ($compiled['modules'] as $manifest) {
            if (!is_array($manifest)) {
                throw new RuntimeException('Compiled module registry contains an invalid manifest.');
            }

            $basePath = (string) ($manifest['base_path'] ?? '');
            unset($manifest['base_path']);
            $definitions[] = ModuleDefinition::fromArray(
                $manifest,
                $paths->resolveFromRoot($basePath)
            );
        }

        $registry = new self($definitions);
        $expected = (string) ($compiled['fingerprint'] ?? '');
        if ($expected === '' || !hash_equals($expected, $registry->fingerprint($paths))) {
            throw new RuntimeException(
                'Compiled module registry fingerprint is invalid. Run the module sync command again.'
            );
        }

        return $registry;
    }

    /** @return array<string, ModuleDefinition> */
    public function all(): array
    {
        return $this->modules;
    }

    public function get(string $id): ?ModuleDefinition
    {
        return $this->modules[strtolower(trim($id))] ?? null;
    }

    public function require(string $id): ModuleDefinition
    {
        return $this->get($id)
            ?? throw new RuntimeException('Unknown module: ' . strtolower(trim($id)));
    }

    /** @return list<string> */
    public function coreIds(): array
    {
        return array_values(array_map(
            static fn (ModuleDefinition $module): string => $module->id,
            array_filter(
                $this->modules,
                static fn (ModuleDefinition $module): bool => $module->isCore()
            )
        ));
    }

    /** @return list<string> */
    public function dependencies(string $id): array
    {
        return $this->get($id)?->dependencyIds() ?? [];
    }

    /** @return list<string> */
    public function dependents(string $id): array
    {
        $id = strtolower(trim($id));
        $dependents = [];

        foreach ($this->modules as $module) {
            if (in_array($id, $module->dependencyIds(), true)) {
                $dependents[] = $module->id;
            }
        }

        return $dependents;
    }

    /** @return array<string, ModuleDefinition> */
    public function installationOrder(): array
    {
        $ordered = [];
        $visited = [];

        $visit = function (string $id) use (&$visit, &$ordered, &$visited): void {
            if (isset($visited[$id])) {
                return;
            }

            foreach ($this->modules[$id]->dependencyIds() as $dependencyId) {
                $visit($dependencyId);
            }

            $visited[$id] = true;
            $ordered[$id] = $this->modules[$id];
        };

        foreach (array_keys($this->modules) as $id) {
            $visit($id);
        }

        return $ordered;
    }

    public function moduleForController(string $controllerClass): ?string
    {
        $controllerClass = ltrim($controllerClass, '\\');
        $shortName = basename(str_replace('\\', '/', $controllerClass));

        foreach ($this->modules as $module) {
            if (
                in_array($controllerClass, $module->controllers, true)
                || in_array($shortName, $module->controllers, true)
            ) {
                return $module->id;
            }
        }

        return null;
    }

    public function fingerprint(ModulePaths $paths): string
    {
        $payload = [];
        foreach ($this->modules as $module) {
            $basePath = $paths->relativeToRoot($module->basePath) ?? $module->basePath;
            $payload[] = $module->toArray(str_replace(DIRECTORY_SEPARATOR, '/', $basePath));
        }

        return hash('sha256', serialize($payload));
    }

    private function validate(): void
    {
        $autoloadOwners = [];
        $classOwners = ['controllers' => [], 'models' => []];

        foreach ($this->modules as $module) {
            foreach ($module->dependencies as $dependency) {
                if ($dependency->id === $module->id) {
                    throw new InvalidModuleException($module->id . ' cannot depend on itself.');
                }

                $required = $this->modules[$dependency->id] ?? null;
                if ($required === null) {
                    throw new InvalidModuleException(
                        $module->id . ' depends on missing module ' . $dependency->id . '.'
                    );
                }

                if ($module->isCore() && !$required->isCore()) {
                    throw new InvalidModuleException(
                        'Core module ' . $module->id
                        . ' cannot depend on optional module ' . $dependency->id . '.'
                    );
                }

                if (
                    $dependency->minimumVersion !== null
                    && version_compare($required->version, $dependency->minimumVersion, '<')
                ) {
                    throw new InvalidModuleException(
                        sprintf(
                            '%s requires %s >= %s; source version is %s.',
                            $module->id,
                            $dependency->id,
                            $dependency->minimumVersion,
                            $required->version
                        )
                    );
                }
            }

            foreach ($module->autoload as $prefix => $directory) {
                if (isset($autoloadOwners[$prefix])) {
                    throw new InvalidModuleException(
                        'PSR-4 prefix ' . $prefix . ' is owned by both '
                        . $autoloadOwners[$prefix] . ' and ' . $module->id . '.'
                    );
                }
                $autoloadOwners[$prefix] = $module->id;

                if (!is_dir($module->resolvePath($directory))) {
                    throw new InvalidModuleException(
                        'Autoload directory does not exist for module '
                        . $module->id . ': ' . $directory
                    );
                }
            }

            foreach (['controllers', 'models'] as $property) {
                foreach ($module->{$property} as $class) {
                    if (isset($classOwners[$property][$class])) {
                        throw new InvalidModuleException(
                            $class . ' is owned by both '
                            . $classOwners[$property][$class] . ' and ' . $module->id . '.'
                        );
                    }
                    $classOwners[$property][$class] = $module->id;

                    if (!str_contains($class, '\\')) {
                        $layer = $property === 'controllers' ? 'Http' : 'Models';
                        $path = $layer . '/' . $class . '.php';
                        if (!is_file($module->resolvePath($path))) {
                            throw new InvalidModuleException(
                                'Declared ' . rtrim($property, 's') . ' file does not exist in '
                                . $module->id . ': ' . $path
                            );
                        }
                    }
                }
            }

            foreach (array_merge($module->routes, $module->extensions) as $path) {
                if (!is_file($module->resolvePath($path))) {
                    throw new InvalidModuleException(
                        'Declared module file does not exist in ' . $module->id . ': ' . $path
                    );
                }
            }

            foreach ($module->migrations as $path) {
                if (!is_file($module->resolvePath($path))) {
                    throw new InvalidModuleException(
                        'Declared migration does not exist in ' . $module->id . ': ' . $path
                    );
                }
            }
        }

        $visiting = [];
        $visited = [];
        $visit = function (string $id) use (&$visit, &$visiting, &$visited): void {
            if (isset($visited[$id])) {
                return;
            }
            if (isset($visiting[$id])) {
                throw new InvalidModuleException('Circular module dependency detected at ' . $id . '.');
            }

            $visiting[$id] = true;
            foreach ($this->modules[$id]->dependencyIds() as $dependencyId) {
                $visit($dependencyId);
            }
            unset($visiting[$id]);
            $visited[$id] = true;
        };

        foreach (array_keys($this->modules) as $id) {
            $visit($id);
        }
    }
}
