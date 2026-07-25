<?php
declare(strict_types=1);

namespace FloCMS\Core\Modules;

enum ModuleKind: string
{
    case Core = 'core';
    case Optional = 'optional';
}
