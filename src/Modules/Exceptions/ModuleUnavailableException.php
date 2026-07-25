<?php
declare(strict_types=1);

namespace FloCMS\Core\Modules\Exceptions;

use RuntimeException;

final class ModuleUnavailableException extends RuntimeException
{
    public function __construct(public readonly string $moduleId)
    {
        parent::__construct('Module is unavailable: ' . $moduleId);
    }
}
