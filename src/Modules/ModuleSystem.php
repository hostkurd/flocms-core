<?php
declare(strict_types=1);

namespace FloCMS\Core\Modules;

use FloCMS\Core\Database;
use FloCMS\Core\Modules\Contracts\ModuleStateRepositoryInterface;
use FloCMS\Core\Support\Container;
use LogicException;

final class ModuleSystem
{
    private static ?ModulePaths $paths = null;
    private static ?Database $database = null;
    private static ?ModuleStateRepositoryInterface $repository = null;
    private static ?ModuleRegistry $registry = null;
    private static ?ModuleManager $manager = null;
    private static ?ModuleAutoloader $autoloader = null;
    private static ?ModuleBootstrapper $bootstrapper = null;
    private static ?ModuleSynchronizer $synchronizer = null;
    private static ?ExtensionRegistry $extensions = null;
    private static ?Container $container = null;
    private static bool $allowRuntimeDiscovery = false;
    private static string $tablePrefix = 'app_';

    public static function configure(
        ModulePaths $paths,
        ?Database $database = null,
        ?ModuleStateRepositoryInterface $repository = null,
        ?Container $container = null,
        bool $allowRuntimeDiscovery = false,
        string $tablePrefix = 'app_'
    ): void {
        self::reset();
        self::$paths = $paths;
        self::$database = $database;
        self::$repository = $repository;
        self::$container = $container ?? new Container();
        self::$allowRuntimeDiscovery = $allowRuntimeDiscovery;
        self::$tablePrefix = $tablePrefix;
    }

    public static function paths(): ModulePaths
    {
        return self::$paths ?? throw new LogicException('ModuleSystem has not been configured.');
    }

    public static function container(): Container
    {
        $container = self::$container
            ?? throw new LogicException('ModuleSystem has not been configured.');

        $container->instance(ModulePaths::class, self::paths());
        $container->instance(ModuleRegistry::class, self::registry());
        $container->instance(ModuleManager::class, self::manager());
        if (self::$database !== null) {
            $container->instance(Database::class, self::$database);
        }

        return $container;
    }

    public static function registry(): ModuleRegistry
    {
        if (self::$registry !== null) {
            return self::$registry;
        }

        $paths = self::paths();
        if (is_file($paths->registryCache)) {
            return self::$registry = ModuleRegistry::fromCache($paths->registryCache, $paths);
        }

        if (!self::$allowRuntimeDiscovery) {
            throw new LogicException(
                'Compiled module registry is missing. Run module sync during deployment.'
            );
        }

        return self::$registry = (new ModuleDiscovery($paths))->discover();
    }

    public static function manager(): ModuleManager
    {
        if (self::$manager !== null) {
            return self::$manager;
        }

        $repository = self::$repository;
        if ($repository === null && self::$database !== null) {
            $repository = self::$repository = new DatabaseModuleStateRepository(
                self::$database,
                self::$tablePrefix
            );
        }

        return self::$manager = new ModuleManager(
            self::registry(),
            self::paths(),
            $repository,
            new ModuleStateCache(self::paths()->stateCache)
        );
    }

    public static function autoloader(): ModuleAutoloader
    {
        return self::$autoloader ??= new ModuleAutoloader(self::registry());
    }

    public static function bootstrapper(): ModuleBootstrapper
    {
        return self::$bootstrapper ??= new ModuleBootstrapper(
            self::registry(),
            self::manager(),
            self::autoloader(),
            self::container()
        );
    }

    public static function synchronizer(): ModuleSynchronizer
    {
        return self::$synchronizer ??= new ModuleSynchronizer(
            self::paths(),
            new ModuleDiscovery(self::paths())
        );
    }

    public static function extensions(): ExtensionRegistry
    {
        return self::$extensions ??= new ExtensionRegistry(self::registry(), self::manager());
    }

    public static function migrationRunner(): ModuleMigrationRunner
    {
        $database = self::$database
            ?? throw new LogicException('A database is required to run module migrations.');
        $repository = self::$repository
            ??= new DatabaseModuleStateRepository($database, self::$tablePrefix);

        return new ModuleMigrationRunner(
            $database,
            self::registry(),
            self::manager(),
            $repository,
            self::$tablePrefix
        );
    }

    public static function reset(): void
    {
        self::$autoloader?->unregister();
        self::$paths = null;
        self::$database = null;
        self::$repository = null;
        self::$registry = null;
        self::$manager = null;
        self::$autoloader = null;
        self::$bootstrapper = null;
        self::$synchronizer = null;
        self::$extensions = null;
        self::$container = null;
        self::$allowRuntimeDiscovery = false;
        self::$tablePrefix = 'app_';
    }
}
