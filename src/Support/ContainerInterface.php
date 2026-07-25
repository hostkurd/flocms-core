<?php
declare(strict_types=1);

namespace FloCMS\Core\Support;

interface ContainerInterface
{
    public function has(string $id): bool;

    public function get(string $id): mixed;
}
