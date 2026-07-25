<?php
declare(strict_types=1);

namespace FloCMS\Core\Modules\Contracts;

use FloCMS\Core\Modules\ModuleDefinition;

interface ModuleStateRepositoryInterface
{
    public function infrastructureReady(): bool;

    public function ensureInfrastructure(): void;

    /** @return array<string, array<string, mixed>> */
    public function all(): array;

    public function upsert(ModuleDefinition $module): void;

    public function setEnabled(string $moduleId, bool $enabled): void;

    /** @param array<string, mixed> $metadata */
    public function audit(string $moduleId, string $action, ?int $actorId, array $metadata = []): void;
}
