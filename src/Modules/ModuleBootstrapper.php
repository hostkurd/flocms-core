<?php
declare(strict_types=1);

namespace FloCMS\Core\Modules;

use FloCMS\Core\Modules\Contracts\ModuleProviderInterface;
use FloCMS\Core\Support\Container;
use RuntimeException;

final class ModuleBootstrapper
{
    private bool $booted = false;

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModuleManager $manager,
        private readonly ModuleAutoloader $autoloader,
        private readonly Container $container
    ) {
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->autoloader->register();
        $providers = [];

        foreach ($this->registry->installationOrder() as $module) {
            if (!$this->manager->isEnabled($module->id)) {
                continue;
            }

            foreach ($module->providers as $providerClass) {
                $provider = $this->container->get($providerClass);
                if (!$provider instanceof ModuleProviderInterface) {
                    throw new RuntimeException(
                        $providerClass . ' must implement ' . ModuleProviderInterface::class . '.'
                    );
                }

                $provider->register($this->container);
                $providers[] = $provider;
            }
        }

        foreach ($providers as $provider) {
            $provider->boot($this->container);
        }

        $this->booted = true;
    }
}
