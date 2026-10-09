<?php
declare(strict_types=1);

namespace FloCMS\Core;

use RuntimeException;

/**
 * Thrown when a model needs the database but DB_NAME / DB_USERNAME are empty.
 */
class DatabaseNotConfiguredException extends RuntimeException
{
    public function __construct(string $message = 'Database is not configured.')
    {
        parent::__construct($message);
    }
}
