<?php
declare(strict_types=1);

namespace FloCMS\Core\Modules\Contracts;

use FloCMS\Core\Support\Container;

interface ModuleProviderInterface
{
    public function register(Container $container): void;

    public function boot(Container $container): void;
}
