<?php
declare(strict_types=1);

namespace FloCMS\Core\Modules;

use FloCMS\Core\Database;
use FloCMS\Core\Modules\Contracts\ModuleStateRepositoryInterface;
use PDO;
use RuntimeException;
use Throwable;

final class DatabaseModuleStateRepository implements ModuleStateRepositoryInterface
{
    private readonly string $modulesTable;
    private readonly string $auditTable;

    public function __construct(
        private readonly Database $database,
        string $tablePrefix = 'app_'
    ) {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $tablePrefix)) {
            throw new RuntimeException('Invalid module table prefix.');
        }

        $this->modulesTable = $tablePrefix . 'modules';
        $this->auditTable = $tablePrefix . 'module_audit_log';
    }

    public function infrastructureReady(): bool
    {
        try {
            $this->database->query('SELECT module_id FROM ' . $this->modulesTable . ' LIMIT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function ensureInfrastructure(): void
    {
        $this->assertMysql();

        foreach ($this->infrastructureStatements() as $statement) {
            $this->database->query($statement);
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        if (!$this->infrastructureReady()) {
            return [];
        }

        $rows = $this->database
            ->query('SELECT * FROM ' . $this->modulesTable . ' ORDER BY priority ASC, module_id ASC')
            ->fetchAll(PDO::FETCH_ASSOC);
        $states = [];

        foreach ($rows as $row) {
            if (is_array($row) && isset($row['module_id'])) {
                $states[(string) $row['module_id']] = $row;
            }
        }

        return $states;
    }

    public function upsert(ModuleDefinition $module): void
    {
        $this->database->query(
            'INSERT INTO ' . $this->modulesTable . ' '
            . '(module_id, name, kind, installed_version, schema_version, is_enabled, priority, installed_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'name = VALUES(name), kind = VALUES(kind), '
            . 'installed_version = VALUES(installed_version), '
            . 'schema_version = VALUES(schema_version), priority = VALUES(priority), '
            . "is_enabled = IF(VALUES(kind) = 'core', 1, is_enabled), "
            . 'updated_at = CURRENT_TIMESTAMP',
            [
                $module->id,
                $module->name,
                $module->kind->value,
                $module->version,
                $module->schemaVersion,
                ($module->isCore() || $module->defaultEnabled) ? 1 : 0,
                $module->priority,
            ]
        );
    }

    public function setEnabled(string $moduleId, bool $enabled): void
    {
        $statement = $this->database->query(
            'UPDATE ' . $this->modulesTable
            . ' SET is_enabled = ?, disabled_at = ?, updated_at = CURRENT_TIMESTAMP'
            . ' WHERE module_id = ?',
            [$enabled ? 1 : 0, $enabled ? null : date('Y-m-d H:i:s'), $moduleId]
        );

        if ($statement->rowCount() === 0) {
            $exists = $this->database
                ->query(
                    'SELECT module_id FROM ' . $this->modulesTable . ' WHERE module_id = ? LIMIT 1',
                    [$moduleId]
                )
                ->fetch(PDO::FETCH_ASSOC);

            if (!is_array($exists)) {
                throw new RuntimeException('Module is not installed: ' . $moduleId);
            }
        }
    }

    /** @param array<string, mixed> $metadata */
    public function audit(string $moduleId, string $action, ?int $actorId, array $metadata = []): void
    {
        if (!$this->infrastructureReady()) {
            return;
        }

        $this->database->query(
            'INSERT INTO ' . $this->auditTable
            . ' (module_id, action, actor_user_id, metadata, created_at)'
            . ' VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)',
            [
                $moduleId,
                $action,
                $actorId,
                json_encode(
                    $metadata,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
            ]
        );
    }

    /** @return list<string> */
    private function infrastructureStatements(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS {$this->modulesTable} (
                module_id VARCHAR(64) NOT NULL,
                name VARCHAR(150) NOT NULL,
                kind VARCHAR(20) NOT NULL DEFAULT 'optional',
                installed_version VARCHAR(64) NOT NULL,
                schema_version INT UNSIGNED NOT NULL DEFAULT 0,
                is_enabled TINYINT(1) NOT NULL DEFAULT 0,
                priority INT NOT NULL DEFAULT 1000,
                installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                disabled_at DATETIME NULL,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (module_id),
                KEY idx_flo_modules_enabled (is_enabled),
                KEY idx_flo_modules_priority (priority)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS {$this->auditTable} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                module_id VARCHAR(64) NOT NULL,
                action VARCHAR(64) NOT NULL,
                actor_user_id BIGINT NULL,
                metadata JSON NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_flo_module_audit_module (module_id, created_at),
                KEY idx_flo_module_audit_actor (actor_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }

    private function assertMysql(): void
    {
        if ($this->database->driver() !== 'mysql') {
            throw new RuntimeException(
                'The bundled module state repository supports MySQL/MariaDB. '
                . 'Bind ModuleStateRepositoryInterface to a driver-specific implementation for other databases.'
            );
        }
    }
}
