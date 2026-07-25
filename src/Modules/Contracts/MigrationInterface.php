<?php
declare(strict_types=1);

namespace FloCMS\Core\Modules\Contracts;

use FloCMS\Core\Database;

interface MigrationInterface
{
    public function id(): string;

    public function up(Database $database): void;
}
