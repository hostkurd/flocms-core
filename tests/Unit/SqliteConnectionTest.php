<?php
declare(strict_types=1);

namespace FloCMS\Core\Tests\Unit;

use FloCMS\Core\App;
use FloCMS\Core\Config;
use FloCMS\Core\Database;
use FloCMS\Core\DatabaseConnectionException;
use FloCMS\Core\DatabaseNotConfiguredException;
use FloCMS\Core\Model;
use PHPUnit\Framework\TestCase;

/**
 * 2.9: websites connect to SQLite with DB_TYPE=sqlite, like `php flo` does.
 * SQLite has no user, so DB_NAME (the file) is all it needs.
 */
final class SqliteConnectionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        Config::$settings = [];
        App::resetDb();
        unset($_ENV['DB_TYPE']);
        $this->dir = sys_get_temp_dir() . '/flo-sqlite-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/storage', 0777, true);
    }

    protected function tearDown(): void
    {
        Config::$settings = [];
        App::resetDb();
        unset($_ENV['DB_TYPE']);
        @unlink($this->dir . '/storage/site.sqlite');
        @rmdir($this->dir . '/storage');
        @rmdir($this->dir);
    }

    private function sqliteFile(): string
    {
        $pdo = new \PDO('sqlite:' . $this->dir . '/storage/site.sqlite');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)');
        $pdo->exec("INSERT INTO users (email) VALUES ('a@example.com')");

        return $this->dir . '/storage/site.sqlite';
    }

    public function testMysqlStillNeedsAUser(): void
    {
        Config::set('db.name', 'site');
        self::assertSame('mysql', App::dbDriver());
        self::assertFalse(App::hasDbConfig());

        Config::set('db.user', 'root');
        self::assertTrue(App::hasDbConfig());
    }

    public function testSqliteNeedsOnlyTheFile(): void
    {
        Config::set('db.type', 'sqlite');
        self::assertFalse(App::hasDbConfig(), 'no DB_NAME yet');

        Config::set('db.name', $this->sqliteFile());
        self::assertSame('sqlite', App::dbDriver());
        self::assertTrue(App::hasDbConfig());

        $db = App::db();
        self::assertInstanceOf(Database::class, $db);
        self::assertSame('a@example.com', $db->table('users')->where('id', '=', 1)->first()?->email);
        self::assertTrue(App::dbStatus()['connected']);
    }

    public function testDbTypeFromEnvWhenTheConfigDoesNotSetIt(): void
    {
        // Sites whose config/config.php has no 'db.type' (skeleton 2.0.0 and older)
        $_ENV['DB_TYPE'] = 'sqlite';
        Config::set('db.name', $this->sqliteFile());

        self::assertSame('sqlite', App::dbDriver());
        self::assertInstanceOf(Database::class, App::db());

        Config::set('db.type', 'mysql');
        self::assertSame('mysql', App::dbDriver(), 'the config wins over .env');
    }

    public function testARelativeFileIsInTheProject(): void
    {
        // tests/bootstrap.php sets ROOT to tests/fixtures
        $relative = 'storage/flo-sqlite-' . bin2hex(random_bytes(4)) . '.sqlite';
        copy($this->sqliteFile(), ROOT . '/' . $relative);
        try {
            Config::set('db.type', 'sqlite');
            Config::set('db.name', $relative);
            self::assertInstanceOf(Database::class, App::db());
        } finally {
            App::resetDb();
            @unlink(ROOT . '/' . $relative);
        }
    }

    public function testAMissingFileIsAClearErrorAndIsNotCreated(): void
    {
        Config::set('db.type', 'sqlite');
        Config::set('db.name', $this->dir . '/storage/typo.sqlite');

        $status = App::dbStatus();
        self::assertTrue($status['configured']);
        self::assertFalse($status['connected']);
        self::assertStringContainsString('php flo migrate', (string) $status['message']);
        self::assertFileDoesNotExist($this->dir . '/storage/typo.sqlite');

        $this->expectException(DatabaseConnectionException::class);
        App::db();
    }

    public function testModelsWorkOnSqlite(): void
    {
        Config::set('db.type', 'sqlite');
        Config::set('db.name', $this->sqliteFile());

        self::assertInstanceOf(Database::class, (new SqliteTestModel())->database());

        Config::set('db.name', '');
        App::resetDb();
        $this->expectException(DatabaseNotConfiguredException::class);
        (new SqliteTestModel())->database();
    }
}

final class SqliteTestModel extends Model
{
    public function database(): Database
    {
        return $this->db;
    }
}
