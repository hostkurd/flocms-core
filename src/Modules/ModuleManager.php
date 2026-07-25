<?php
declare(strict_types=1);

namespace FloCMS\Core\Modules;

use FloCMS\Core\Modules\Contracts\ModuleStateRepositoryInterface;
use FloCMS\Core\Modules\Exceptions\ModuleUnavailableException;
use RuntimeException;

final class ModuleManager
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $stateCache = null;
    private ?bool $infrastructureReady = null;
    private readonly string $registryFingerprint;

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModulePaths $paths,
        private readonly ?ModuleStateRepositoryInterface $repository = null,
        private readonly ?ModuleStateCache $cache = null
    ) {
        $this->registryFingerprint = $registry->fingerprint($paths);
    }

    public function infrastructureReady(bool $refresh = false): bool
    {
        if (!$refresh && $this->infrastructureReady !== null) {
            return $this->infrastructureReady;
        }

        if (!$refresh && $this->cache !== null) {
            $cached = $this->cache->read($this->registryFingerprint);
            if ($cached !== null) {
                $this->stateCache = $cached['modules'];

                return $this->infrastructureReady = $cached['infrastructure_ready'];
            }
        }

        return $this->infrastructureReady = $this->repository?->infrastructureReady() ?? false;
    }

    /** @return array<string, array<string, mixed>> */
    public function storedStates(bool $refresh = false): array
    {
        if (!$refresh && $this->stateCache !== null) {
            return $this->stateCache;
        }

        if (!$refresh && $this->cache !== null) {
            $cached = $this->cache->read($this->registryFingerprint);
            if ($cached !== null) {
                $this->infrastructureReady = $cached['infrastructure_ready'];

                return $this->stateCache = $cached['modules'];
            }
        }

        $ready = $this->repository?->infrastructureReady() ?? false;
        $states = $ready ? $this->repository->all() : [];
        $this->infrastructureReady = $ready;
        $this->stateCache = $states;
        $this->cache?->write($this->registryFingerprint, $ready, $states);

        return $states;
    }

    /** @return list<array<string, mixed>> */
    public function runtimeStates(bool $refresh = false): array
    {
        $stored = $this->storedStates($refresh);
        $ready = $this->infrastructureReady;
        $enabledIds = [];

        foreach ($this->registry->all() as $id => $manifest) {
            $row = $stored[$id] ?? null;
            $enabled = $ready
                ? $row !== null
                    && ($manifest->isCore() || (bool) ($row['is_enabled'] ?? false))
                : ($manifest->isCore() || $manifest->defaultEnabled);

            if ($enabled) {
                $enabledIds[$id] = true;
            }
        }

        $states = [];
        foreach ($this->registry->all() as $id => $manifest) {
            $row = $stored[$id] ?? null;
            $installed = $row !== null;
            $enabled = isset($enabledIds[$id]);
            $missingDependencies = [];

            foreach ($manifest->dependencies as $dependency) {
                $dependencyManifest = $this->registry->require($dependency->id);
                $dependencyRow = $stored[$dependency->id] ?? null;
                $unavailable = !isset($enabledIds[$dependency->id]);

                if ($ready) {
                    $unavailable = $unavailable
                        || $dependencyRow === null
                        || (int) ($dependencyRow['schema_version'] ?? 0)
                            < $dependencyManifest->schemaVersion
                        || (
                            $dependency->minimumVersion !== null
                            && version_compare(
                                (string) ($dependencyRow['installed_version'] ?? '0.0.0'),
                                $dependency->minimumVersion,
                                '<'
                            )
                        );
                }

                if ($unavailable) {
                    $missingDependencies[] = $dependency->id;
                }
            }

            $enabledDependents = array_values(array_filter(
                $this->registry->dependents($id),
                static fn (string $dependent): bool => isset($enabledIds[$dependent])
            ));
            $installedSchema = (int) ($row['schema_version'] ?? 0);
            $installedVersion = isset($row['installed_version'])
                ? (string) $row['installed_version']
                : null;
            $migrationRequired = !$ready
                || ($installed && $installedSchema < $manifest->schemaVersion);
            $updateRequired = $installedVersion !== null
                && version_compare($installedVersion, $manifest->version, '<');

            [$status, $reason] = $this->status(
                $ready,
                $installed,
                $enabled,
                $migrationRequired,
                $updateRequired,
                $missingDependencies
            );

            $states[] = [
                'id' => $id,
                'name' => $manifest->name,
                'description' => $manifest->description,
                'kind' => $manifest->kind->value,
                'priority' => $manifest->priority,
                'installed' => $installed,
                'enabled' => $enabled,
                'locked' => $manifest->isCore(),
                'version' => $manifest->version,
                'installedVersion' => $installedVersion,
                'schemaVersion' => $manifest->schemaVersion,
                'installedSchemaVersion' => $installedSchema,
                'status' => $status,
                'dependencies' => $manifest->dependencyIds(),
                'missingDependencies' => array_values(array_unique($missingDependencies)),
                'enabledDependents' => $enabledDependents,
                'canEnable' => $ready
                    && $installed
                    && !$enabled
                    && $missingDependencies === []
                    && !$migrationRequired
                    && !$updateRequired,
                'canDisable' => $ready
                    && $installed
                    && $enabled
                    && !$manifest->isCore()
                    && $enabledDependents === [],
                'reason' => $reason,
            ];
        }

        return $states;
    }

    public function isEnabled(string $id): bool
    {
        return $this->isEnabledWithDependencies(strtolower(trim($id)), []);
    }

    public function assertEnabled(string $id): void
    {
        if (!$this->isEnabled($id)) {
            throw new ModuleUnavailableException(strtolower(trim($id)));
        }
    }

    public function assertEnabledForController(string $controllerClass): void
    {
        $moduleId = $this->registry->moduleForController($controllerClass);
        if ($moduleId !== null) {
            $this->assertEnabled($moduleId);
        }
    }

    /** @return array<string, mixed> */
    public function setEnabled(string $id, bool $enabled, ?int $actorId = null): array
    {
        $repository = $this->repository
            ?? throw new RuntimeException('A module state repository is required for state changes.');
        $module = $this->registry->require($id);

        if (!$repository->infrastructureReady()) {
            throw new RuntimeException('Module database infrastructure is not installed.');
        }
        if ($module->isCore() && !$enabled) {
            throw new RuntimeException('Core modules cannot be disabled.');
        }

        $states = [];
        foreach ($this->runtimeStates(true) as $state) {
            $states[(string) $state['id']] = $state;
        }
        $state = $states[$module->id] ?? null;
        if ($state === null || empty($state['installed'])) {
            throw new RuntimeException('Module must be migrated before its state can change.');
        }
        if ($enabled && $state['missingDependencies'] !== []) {
            throw new RuntimeException(
                'Enable required modules first: ' . implode(', ', $state['missingDependencies']) . '.'
            );
        }
        if (!$enabled && $state['enabledDependents'] !== []) {
            throw new RuntimeException(
                'Disable dependent modules first: ' . implode(', ', $state['enabledDependents']) . '.'
            );
        }
        if ($enabled && in_array($state['status'], ['migration-required', 'update-required'], true)) {
            throw new RuntimeException('Apply module migrations before enabling this module.');
        }

        $repository->setEnabled($module->id, $enabled);
        $repository->audit($module->id, $enabled ? 'enabled' : 'disabled', $actorId, [
            'previous' => (bool) $state['enabled'],
            'current' => $enabled,
        ]);

        foreach ($this->runtimeStates(true) as $freshState) {
            if ($freshState['id'] === $module->id) {
                return $freshState;
            }
        }

        throw new RuntimeException('Module state could not be refreshed.');
    }

    /** @return array<string, mixed> */
    public function publicState(): array
    {
        $states = $this->runtimeStates();

        return [
            'enabled' => array_values(array_map(
                static fn (array $state): string => (string) $state['id'],
                array_filter($states, static fn (array $state): bool => (bool) $state['enabled'])
            )),
            'versions' => array_reduce(
                $states,
                static function (array $versions, array $state): array {
                    $versions[(string) $state['id']] = (string) $state['version'];

                    return $versions;
                },
                []
            ),
            'infrastructure_ready' => $this->infrastructureReady(),
        ];
    }

    public function refresh(): void
    {
        $this->stateCache = null;
        $this->infrastructureReady = null;
        $this->storedStates(true);
    }

    /** @param array<string, true> $visiting */
    private function isEnabledWithDependencies(string $id, array $visiting): bool
    {
        $module = $this->registry->get($id);
        if ($module === null) {
            return false;
        }
        if ($module->isCore() && !$this->infrastructureReady()) {
            return true;
        }
        if (isset($visiting[$id])) {
            return false;
        }
        $visiting[$id] = true;

        $stored = $this->storedStates();
        $ready = (bool) $this->infrastructureReady;
        $row = $stored[$id] ?? null;
        $enabled = $module->isCore()
            || ($ready ? (bool) ($row['is_enabled'] ?? false) : $module->defaultEnabled);
        if (!$enabled) {
            return false;
        }
        if ($ready && $row === null) {
            return false;
        }
        if (
            $ready
            && (
                (int) ($row['schema_version'] ?? 0) < $module->schemaVersion
                || version_compare(
                    (string) ($row['installed_version'] ?? '0.0.0'),
                    $module->version,
                    '<'
                )
            )
        ) {
            return false;
        }

        foreach ($module->dependencies as $dependency) {
            if (!$this->isEnabledWithDependencies($dependency->id, $visiting)) {
                return false;
            }

            if ($ready && $dependency->minimumVersion !== null) {
                $dependencyRow = $stored[$dependency->id] ?? null;
                if (
                    $dependencyRow === null
                    || version_compare(
                        (string) ($dependencyRow['installed_version'] ?? '0.0.0'),
                        $dependency->minimumVersion,
                        '<'
                    )
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param list<string> $missingDependencies
     * @return array{0: string, 1: ?string}
     */
    private function status(
        bool $ready,
        bool $installed,
        bool $enabled,
        bool $migrationRequired,
        bool $updateRequired,
        array $missingDependencies
    ): array {
        $status = 'disabled';
        $reason = null;

        if (!$ready) {
            $status = 'migration-required';
            $reason = 'Database module migrations must be applied.';
        } elseif (!$installed) {
            $status = 'discovered';
            $reason = 'Run module migrations to install this module.';
        } elseif ($migrationRequired) {
            $status = 'migration-required';
            $reason = 'Database module migrations must be applied.';
        } elseif ($updateRequired) {
            $status = 'update-required';
            $reason = 'A newer module source version is available.';
        } elseif ($enabled) {
            $status = 'enabled';
        }

        if ($missingDependencies !== []) {
            $status = 'incompatible';
            $reason = 'Required modules are disabled, missing, or outdated: '
                . implode(', ', array_unique($missingDependencies)) . '.';
        }

        return [$status, $reason];
    }
}
