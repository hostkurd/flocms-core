<?php
declare(strict_types=1);

namespace FloCMS\Core\Modules;

use RuntimeException;

/**
 * Compatibility integration for Flo's page builder.
 *
 * New generic integrations should use ExtensionRegistry. This wrapper remains
 * because Pages is a first-party Flo module and existing projects already use
 * the page-block integration shape.
 */
final class PageBlockRegistry
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $integrations = null;

    /** @return list<array<string, mixed>> */
    public static function definitions(?ModuleManager $modules = null): array
    {
        $modules ??= ModuleSystem::manager();
        $definitions = [];

        foreach (self::integrations() as $moduleId => $integration) {
            if ($modules->isEnabled($moduleId)) {
                $definitions = array_merge($definitions, $integration['definitions']);
            }
        }

        return array_values($definitions);
    }

    /** @return array<string, list<mixed>> */
    public static function resources(?ModuleManager $modules, string $lang): array
    {
        $modules ??= ModuleSystem::manager();
        $resources = [];

        foreach (self::integrations() as $moduleId => $integration) {
            if (!$modules->isEnabled($moduleId)) {
                continue;
            }
            foreach ($integration['resources'] as $resourceKey => $provider) {
                if (!is_callable($provider)) {
                    throw new RuntimeException(
                        'Invalid page-block resource provider ' . $resourceKey
                        . ' in module ' . $moduleId . '.'
                    );
                }
                $resolved = $provider($lang);
                $resources[$resourceKey] = is_array($resolved) ? array_values($resolved) : [];
            }
        }

        return $resources;
    }

    public static function moduleIdForType(string $type): ?string
    {
        $type = strtolower(trim($type));
        foreach (self::integrations() as $moduleId => $integration) {
            if (isset($integration['definitions_by_type'][$type])) {
                return $moduleId;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public static function defaultSettingsForType(string $type): ?array
    {
        $type = strtolower(trim($type));
        foreach (self::integrations() as $integration) {
            $definition = $integration['definitions_by_type'][$type] ?? null;
            if (is_array($definition)) {
                $defaults = $definition['default_settings'] ?? [];

                return is_array($defaults) ? $defaults : [];
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public static function resolve(
        ?ModuleManager $modules,
        string $type,
        array $settings,
        string $lang
    ): ?array {
        $modules ??= ModuleSystem::manager();
        $type = strtolower(trim($type));
        $moduleId = self::moduleIdForType($type);
        if ($moduleId === null) {
            return null;
        }

        $integration = self::integrations()[$moduleId];
        if (!$modules->isEnabled($moduleId)) {
            $disabledData = $integration['disabled_data'][$type] ?? [];

            return is_array($disabledData) ? $disabledData : [];
        }

        $resolver = $integration['resolvers'][$type] ?? null;
        if (!is_callable($resolver)) {
            return [];
        }

        $resolved = $resolver($settings, $lang);

        return is_array($resolved) ? $resolved : [];
    }

    public static function clear(): void
    {
        self::$integrations = null;
    }

    /** @return array<string, array<string, mixed>> */
    private static function integrations(): array
    {
        if (self::$integrations !== null) {
            return self::$integrations;
        }

        $integrations = [];
        $typeOwners = [];

        foreach (ModuleSystem::registry()->all() as $module) {
            $relativePath = $module->extensions['page-blocks']
                ?? $module->extensions['page_blocks']
                ?? null;

            // Flo 2 compatibility for modules created before extensions were
            // declared in module.php. New modules should declare the path.
            if ($relativePath === null) {
                $legacy = $module->basePath . DIRECTORY_SEPARATOR
                    . 'Integrations' . DIRECTORY_SEPARATOR . 'page-blocks.php';
                if (!is_file($legacy)) {
                    continue;
                }
                $path = $legacy;
            } else {
                $path = $module->resolvePath($relativePath);
            }

            $integration = require $path;
            if (!is_array($integration) || ($integration['module_id'] ?? null) !== $module->id) {
                throw new RuntimeException(
                    'Invalid page-block integration for module ' . $module->id . '.'
                );
            }

            $definitionsByType = [];
            foreach ($integration['definitions'] ?? [] as $definition) {
                if (!is_array($definition)) {
                    throw new RuntimeException(
                        'Invalid page-block definition in module ' . $module->id . '.'
                    );
                }
                $type = strtolower(trim((string) ($definition['type'] ?? '')));
                if (!preg_match('/^[a-z][a-z0-9_]*$/', $type)) {
                    throw new RuntimeException(
                        'Invalid page-block type in module ' . $module->id . '.'
                    );
                }
                if (isset($typeOwners[$type])) {
                    throw new RuntimeException(
                        'Page-block type ' . $type . ' is owned by both '
                        . $typeOwners[$type] . ' and ' . $module->id . '.'
                    );
                }

                $definition['type'] = $type;
                $definitionsByType[$type] = $definition;
                $typeOwners[$type] = $module->id;
            }

            $integration['definitions'] = array_values($definitionsByType);
            $integration['definitions_by_type'] = $definitionsByType;
            foreach (['resolvers', 'disabled_data', 'resources'] as $key) {
                $integration[$key] = is_array($integration[$key] ?? null)
                    ? $integration[$key]
                    : [];
            }
            $integrations[$module->id] = $integration;
        }

        return self::$integrations = $integrations;
    }
}
