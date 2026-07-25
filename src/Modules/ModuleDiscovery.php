<?php
declare(strict_types=1);

namespace FloCMS\Core\Modules;

use DirectoryIterator;
use FloCMS\Core\Modules\Exceptions\InvalidModuleException;
use RuntimeException;

final class ModuleDiscovery
{
    public function __construct(private readonly ModulePaths $paths)
    {
    }

    public function discover(): ModuleRegistry
    {
        $definitions = [];

        foreach ($this->paths->moduleDirectories as $moduleDirectory) {
            if (!is_dir($moduleDirectory)) {
                continue;
            }

            $root = realpath($moduleDirectory);
            if ($root === false) {
                throw new RuntimeException('Unable to resolve module directory: ' . $moduleDirectory);
            }

            $entries = [];
            foreach (new DirectoryIterator($root) as $entry) {
                if ($entry->isDot() || !$entry->isDir()) {
                    continue;
                }
                $entries[] = $entry->getFilename();
            }
            sort($entries, SORT_STRING);

            foreach ($entries as $entry) {
                if (!preg_match('/^[a-z][a-z0-9-]*$/', $entry)) {
                    throw new InvalidModuleException('Invalid module directory name: ' . $entry);
                }

                $basePath = $root . DIRECTORY_SEPARATOR . $entry;
                $realBasePath = realpath($basePath);
                if (
                    $realBasePath === false
                    || !str_starts_with(
                        $realBasePath . DIRECTORY_SEPARATOR,
                        rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
                    )
                ) {
                    throw new InvalidModuleException('Module path escapes its configured root: ' . $entry);
                }

                $manifestPath = $realBasePath . DIRECTORY_SEPARATOR . 'module.php';
                if (!is_file($manifestPath)) {
                    continue;
                }

                $manifest = require $manifestPath;
                if (!is_array($manifest)) {
                    throw new InvalidModuleException(
                        'Module manifest must return an array: ' . $manifestPath
                    );
                }
                if (($manifest['id'] ?? null) !== $entry) {
                    throw new InvalidModuleException(
                        $entry . '/module.php must use the directory name as its id.'
                    );
                }

                $definitions[] = ModuleDefinition::fromArray($manifest, $realBasePath);
            }
        }

        return new ModuleRegistry($definitions);
    }
}
