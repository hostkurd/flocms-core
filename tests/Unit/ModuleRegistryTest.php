<?php
declare(strict_types=1);

namespace FloCMS\Core\Tests\Unit;

use FloCMS\Core\Modules\Contracts\ModuleStateRepositoryInterface;
use FloCMS\Core\Modules\Exceptions\InvalidModuleException;
use FloCMS\Core\Modules\ModuleDefinition;
use FloCMS\Core\Modules\ModuleManager;
use FloCMS\Core\Modules\ModulePaths;
use FloCMS\Core\Modules\ModuleRegistry;
use FloCMS\Core\Model;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ModuleRegistryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/flo-core-test-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/modules/system/src', 0777, true);
        mkdir($this->root . '/modules/news/src', 0777, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $this->root,
                RecursiveDirectoryIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testDependenciesDetermineInstallationOrder(): void
    {
        $system = ModuleDefinition::fromArray(
            $this->manifest('system', 'core'),
            $this->root . '/modules/system'
        );
        $news = ModuleDefinition::fromArray(
            $this->manifest('news', 'optional', [
                ['id' => 'system', 'minimum_version' => '1.0.0'],
            ]),
            $this->root . '/modules/news'
        );

        $registry = new ModuleRegistry([$news, $system]);

        self::assertSame(['system', 'news'], array_keys($registry->installationOrder()));
        self::assertSame(['news'], $registry->dependents('system'));
    }

    public function testManifestPathsCannotEscapeTheirModule(): void
    {
        $manifest = $this->manifest('news');
        $manifest['migrations'] = ['../outside.php'];

        $this->expectException(InvalidModuleException::class);
        ModuleDefinition::fromArray($manifest, $this->root . '/modules/news');
    }

    public function testCoreModuleIsUnavailableUntilInstalledWhenInfrastructureExists(): void
    {
        $module = ModuleDefinition::fromArray(
            $this->manifest('system', 'core'),
            $this->root . '/modules/system'
        );
        $registry = new ModuleRegistry([$module]);
        $paths = ModulePaths::fromRoot($this->root);
        $repository = new class implements ModuleStateRepositoryInterface {
            public function infrastructureReady(): bool
            {
                return true;
            }

            public function ensureInfrastructure(): void
            {
            }

            public function all(): array
            {
                return [];
            }

            public function upsert(ModuleDefinition $module): void
            {
            }

            public function setEnabled(string $moduleId, bool $enabled): void
            {
            }

            public function audit(
                string $moduleId,
                string $action,
                ?int $actorId,
                array $metadata = []
            ): void {
            }
        };

        $manager = new ModuleManager($registry, $paths, $repository);

        self::assertFalse($manager->isEnabled('system'));
        self::assertSame('discovered', $manager->runtimeStates()[0]['status']);
    }

    public function testLegacyPagingMetadataDoesNotFailForLargeResultSets(): void
    {
        $model = (new \ReflectionClass(Model::class))->newInstanceWithoutConstructor();
        $paging = $model->pagingArray(2, 10, 100);

        self::assertSame(10, $paging['total_pages']);
        self::assertSame([7, 8, 9], $paging['last_pages']);
        self::assertSame(1, $paging['prev_page']);
    }

    /**
     * @param list<array{id: string, minimum_version?: string}> $dependencies
     * @return array<string, mixed>
     */
    private function manifest(
        string $id,
        string $kind = 'optional',
        array $dependencies = []
    ): array {
        return [
            'id' => $id,
            'name' => ucfirst($id),
            'version' => '1.0.0',
            'schema_version' => 0,
            'kind' => $kind,
            'default_enabled' => true,
            'dependencies' => $dependencies,
            'autoload' => [
                'Tests\\' . ucfirst($id) . '\\' => 'src',
            ],
        ];
    }
}
