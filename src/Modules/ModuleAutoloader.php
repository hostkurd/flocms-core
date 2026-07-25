<?php
declare(strict_types=1);

namespace FloCMS\Core\Modules;

final class ModuleAutoloader
{
    /** @var array<string, string> */
    private array $prefixes = [];

    /** @var array<class-string, string> */
    private array $classes = [];

    private bool $registered = false;

    public function __construct(ModuleRegistry $registry)
    {
        foreach ($registry->all() as $module) {
            foreach ($module->controllers as $controller) {
                if (!str_contains($controller, '\\')) {
                    $this->classes['FloCMS\\Controllers\\' . $controller] =
                        $module->resolvePath('Http/' . $controller . '.php');
                }
            }
            foreach ($module->models as $model) {
                if (!str_contains($model, '\\')) {
                    $this->classes['FloCMS\\Models\\' . $model] =
                        $module->resolvePath('Models/' . $model . '.php');
                }
            }

            foreach ($module->autoload as $prefix => $relativeDirectory) {
                $this->prefixes[$prefix] = rtrim(
                    $module->resolvePath($relativeDirectory),
                    DIRECTORY_SEPARATOR
                ) . DIRECTORY_SEPARATOR;
            }
        }

        uksort(
            $this->prefixes,
            static fn (string $left, string $right): int => strlen($right) <=> strlen($left)
        );
    }

    public function register(bool $prepend = false): void
    {
        if ($this->registered) {
            return;
        }

        spl_autoload_register([$this, 'load'], true, $prepend);
        $this->registered = true;
    }

    public function unregister(): void
    {
        if (!$this->registered) {
            return;
        }

        spl_autoload_unregister([$this, 'load']);
        $this->registered = false;
    }

    public function load(string $class): void
    {
        $class = ltrim($class, '\\');

        if (isset($this->classes[$class])) {
            require_once $this->classes[$class];

            return;
        }

        foreach ($this->prefixes as $prefix => $directory) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            if (
                $relative === ''
                || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $relative)
            ) {
                return;
            }

            $path = $directory . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
            if (is_file($path)) {
                require_once $path;
            }

            return;
        }
    }
}
