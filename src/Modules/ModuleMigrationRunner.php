<?php
declare(strict_types=1);

namespace FloCMS\Core\Modules;

use FloCMS\Core\Database;
use FloCMS\Core\Modules\Contracts\MigrationInterface;
use FloCMS\Core\Modules\Contracts\ModuleStateRepositoryInterface;
use FloCMS\Core\Modules\Exceptions\ModuleMigrationException;
use PDO;
use Throwable;

final class ModuleMigrationRunner
{
    private readonly string $migrationsTable;
    private readonly string $lockName;

    public function __construct(
        private readonly Database $database,
        private readonly ModuleRegistry $registry,
        private readonly ModuleManager $manager,
        private readonly ModuleStateRepositoryInterface $states,
        string $tablePrefix = 'app_'
    ) {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $tablePrefix)) {
            throw new ModuleMigrationException('Invalid module table prefix.');
        }

        $this->migrationsTable = $tablePrefix . 'module_migrations';
        $this->lockName = substr($tablePrefix . 'module_migrations', 0, 64);
    }

    /**
     * @return array{applied: list<string>, skipped: list<string>, pending: list<string>}
     */
    public function migrateAll(bool $dryRun = false, int $lockTimeoutSeconds = 10): array
    {
        $this->acquireLock($lockTimeoutSeconds);

        try {
            $this->states->ensureInfrastructure();
            $this->ensureMigrationTable();

            $applied = [];
            $skipped = [];
            $pending = [];
            $batch = $this->nextBatch();

            foreach ($this->registry->installationOrder() as $module) {
                foreach ($module->migrations as $relativePath) {
                    $path = $module->resolvePath($relativePath);
                    [$migrationId, $runner, $transactional] = $this->loadMigration($path);
                    $key = $module->id . ':' . $migrationId;
                    $checksum = hash_file('sha256', $path);
                    if (!is_string($checksum) || $checksum === '') {
                        throw new ModuleMigrationException('Unable to checksum migration: ' . $path);
                    }

                    $existing = $this->database->query(
                        'SELECT checksum FROM ' . $this->migrationsTable
                        . ' WHERE module_id = ? AND migration = ? LIMIT 1',
                        [$module->id, $migrationId]
                    )->fetch(PDO::FETCH_ASSOC);

                    if (is_array($existing)) {
                        if (!hash_equals((string) ($existing['checksum'] ?? ''), $checksum)) {
                            throw new ModuleMigrationException(
                                'Applied migration was modified: ' . $key
                            );
                        }
                        $skipped[] = $key;
                        continue;
                    }

                    $pending[] = $key;
                    if ($dryRun) {
                        continue;
                    }

                    try {
                        if ($transactional) {
                            $this->database->transaction(
                                static fn (Database $database): mixed => $runner($database)
                            );
                        } else {
                            $runner($this->database);
                        }

                        $this->database->query(
                            'INSERT INTO ' . $this->migrationsTable
                            . ' (module_id, migration, batch, checksum, executed_at)'
                            . ' VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)',
                            [$module->id, $migrationId, $batch, $checksum]
                        );
                    } catch (Throwable $error) {
                        throw new ModuleMigrationException(
                            'Migration failed at ' . $key . ': ' . $error->getMessage(),
                            0,
                            $error
                        );
                    }

                    $applied[] = $key;
                }

                if (!$dryRun) {
                    $this->states->upsert($module);
                    $this->states->audit($module->id, 'migrated', null, [
                        'version' => $module->version,
                        'schema_version' => $module->schemaVersion,
                        'batch' => $batch,
                    ]);
                }
            }

            if (!$dryRun) {
                $this->manager->refresh();
            }

            return ['applied' => $applied, 'skipped' => $skipped, 'pending' => $pending];
        } finally {
            $this->releaseLock();
        }
    }

    private function ensureMigrationTable(): void
    {
        if ($this->database->driver() !== 'mysql') {
            throw new ModuleMigrationException(
                'The bundled migration ledger supports MySQL/MariaDB. '
                . 'Provide a driver-specific migration runner for another database.'
            );
        }

        $this->database->query(
            "CREATE TABLE IF NOT EXISTS {$this->migrationsTable} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                module_id VARCHAR(64) NOT NULL,
                migration VARCHAR(190) NOT NULL,
                batch INT UNSIGNED NOT NULL DEFAULT 1,
                checksum CHAR(64) NOT NULL,
                executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_flo_module_migration (module_id, migration),
                KEY idx_flo_module_migrations_batch (batch)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function nextBatch(): int
    {
        $row = $this->database
            ->query('SELECT COALESCE(MAX(batch), 0) + 1 AS next_batch FROM ' . $this->migrationsTable)
            ->fetch(PDO::FETCH_ASSOC);

        return max(1, (int) ($row['next_batch'] ?? 1));
    }

    /**
     * @return array{0: string, 1: callable(Database): mixed, 2: bool}
     */
    private function loadMigration(string $path): array
    {
        if (!is_file($path)) {
            throw new ModuleMigrationException('Migration file is missing: ' . $path);
        }

        $migration = require $path;

        if ($migration instanceof MigrationInterface) {
            $id = trim($migration->id());
            $this->assertMigrationId($id, $path);

            return [
                $id,
                static fn (Database $database): mixed => $migration->up($database),
                false,
            ];
        }

        if (!is_array($migration)) {
            throw new ModuleMigrationException(
                'Migration must return an array or MigrationInterface: ' . $path
            );
        }

        $id = trim((string) ($migration['id'] ?? ''));
        $this->assertMigrationId($id, $path);
        $up = $migration['up'] ?? null;

        if (is_callable($up)) {
            $runner = static fn (Database $database): mixed => $up($database);
        } elseif (is_array($up) && array_is_list($up)) {
            $runner = static function (Database $database) use ($up): void {
                foreach ($up as $statement) {
                    $sql = trim((string) $statement);
                    if ($sql !== '') {
                        $database->query($sql);
                    }
                }
            };
        } else {
            throw new ModuleMigrationException('Migration "up" must be callable or a SQL list: ' . $path);
        }

        return [$id, $runner, (bool) ($migration['transactional'] ?? false)];
    }

    private function assertMigrationId(string $id, string $path): void
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,189}$/', $id)) {
            throw new ModuleMigrationException('Invalid migration id in ' . $path . '.');
        }
    }

    private function acquireLock(int $timeoutSeconds): void
    {
        if ($this->database->driver() !== 'mysql') {
            return;
        }

        $row = $this->database
            ->query(
                'SELECT GET_LOCK(?, ?) AS acquired',
                [$this->lockName, max(0, $timeoutSeconds)]
            )
            ->fetch(PDO::FETCH_ASSOC);

        if ((int) ($row['acquired'] ?? 0) !== 1) {
            throw new ModuleMigrationException(
                'Another module migration is already running. Try again after it finishes.'
            );
        }
    }

    private function releaseLock(): void
    {
        if ($this->database->driver() !== 'mysql') {
            return;
        }

        try {
            $this->database->query('SELECT RELEASE_LOCK(?)', [$this->lockName]);
        } catch (Throwable) {
            // Connection close also releases a MySQL advisory lock.
        }
    }
}
