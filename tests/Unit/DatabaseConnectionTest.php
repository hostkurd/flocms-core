<?php
declare(strict_types=1);

namespace FloCMS\Core\Tests\Unit;

use FloCMS\Core\App;
use FloCMS\Core\Config;
use FloCMS\Core\Database;
use FloCMS\Core\DatabaseConnectionException;
use FloCMS\Core\DatabaseNotConfiguredException;
use FloCMS\Core\ErrorHandler;
use FloCMS\Core\HttpException;
use FloCMS\Core\Model;
use FloCMS\Core\Tests\Support\MySqlConfig;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatabaseConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        Config::$settings = [];
        App::resetDb();
    }

    protected function tearDown(): void
    {
        Config::$settings = [];
        App::resetDb();
    }

    public function testModelCanBeCreatedWithoutDatabaseConfig(): void
    {
        $model = new TestModel();

        self::assertInstanceOf(Model::class, $model);
    }

    public function testModelThrowsTypedExceptionOnFirstQueryWhenNotConfigured(): void
    {
        $model = new TestModel();

        $this->expectException(DatabaseNotConfiguredException::class);
        $model->database();
    }

    public function testNotConfiguredIsStillAnException(): void
    {
        // Code that caught \Exception before 2.2 keeps working
        self::assertInstanceOf(\Exception::class, new DatabaseNotConfiguredException());
    }

    public function testModelUsesInjectedConnectionLazily(): void
    {
        App::$db = new Database(new PDO('sqlite::memory:'));

        $model = new TestModel();

        self::assertSame(App::$db, $model->database());
        self::assertSame(App::$db, $model->database());
    }

    public function testUndefinedPropertyStillWarns(): void
    {
        $model = new TestModel();
        $warning = null;
        set_error_handler(function (int $no, string $msg) use (&$warning): bool {
            $warning = $msg;
            return true;
        });

        try {
            $value = $model->missing;
        } finally {
            restore_error_handler();
        }

        self::assertNull($value);
        self::assertStringContainsString('Undefined property', (string) $warning);
    }

    public function testDbStatusWhenNotConfigured(): void
    {
        $status = App::dbStatus();

        self::assertFalse($status['configured']);
        self::assertFalse($status['connected']);
        self::assertSame('not_configured', $status['reason']);
    }

    #[DataProvider('driverMessages')]
    public function testDriverCodeIsParsedFromConnectionMessages(string $message, ?int $code, string $reason): void
    {
        $e = DatabaseConnectionException::fromPdo(new PDOException($message));

        self::assertSame($code, $e->driverCode());
        self::assertSame($reason, $e->reason());
        self::assertSame($message, $e->detail());
        self::assertSame('Database connection failed. Check DB config.', $e->getMessage());
        self::assertInstanceOf(RuntimeException::class, $e);
    }

    public static function driverMessages(): array
    {
        return [
            'refused' => ['SQLSTATE[HY000] [2002] Connection refused', 2002, DatabaseConnectionException::REASON_SERVER_UNAVAILABLE],
            'gone away' => ['SQLSTATE[HY000] [2006] MySQL server has gone away', 2006, DatabaseConnectionException::REASON_SERVER_UNAVAILABLE],
            'access denied' => ["SQLSTATE[HY000] [1045] Access denied for user 'root'@'localhost'", 1045, DatabaseConnectionException::REASON_ACCESS_DENIED],
            'unknown db' => ["SQLSTATE[HY000] [1049] Unknown database 'flocms'", 1049, DatabaseConnectionException::REASON_UNKNOWN_DATABASE],
            'no code' => ['could not find driver', null, DatabaseConnectionException::REASON_OTHER],
        ];
    }

    public function testErrorPagesForDatabaseErrors(): void
    {
        $down = DatabaseConnectionException::fromPdo(new PDOException('SQLSTATE[HY000] [2002] Connection refused'));
        $denied = DatabaseConnectionException::fromPdo(new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'root'"));

        self::assertSame([503, 'nodbserver.html'], ErrorHandler::mapToErrorPage($down));
        self::assertSame([500, 'dberror.html'], ErrorHandler::mapToErrorPage($denied));
        self::assertSame([500, 'dberror.html'], ErrorHandler::mapToErrorPage(new DatabaseNotConfiguredException()));
        // Wrapped by application code
        self::assertSame([503, 'nodbserver.html'], ErrorHandler::mapToErrorPage(new RuntimeException('x', 0, $down)));
        // Other errors unchanged
        self::assertSame([500, '500.html'], ErrorHandler::mapToErrorPage(new RuntimeException('x')));
        self::assertSame([404, '404.html'], ErrorHandler::mapToErrorPage(new HttpException(404)));
    }

    public function testDriverDetailIsOnlyShownInDebugMode(): void
    {
        $denied = DatabaseConnectionException::fromPdo(new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'root'"));

        $production = ErrorHandler::errorPageData($denied, 500, false);
        $debug = ErrorHandler::errorPageData($denied, 500, true);

        self::assertSame('Database access denied (invalid username/password).', $production['message']);
        self::assertSame(1045, $production['errorCode']);
        self::assertNull($production['detail']);
        self::assertStringContainsString("Access denied for user 'root'", (string) $debug['detail']);
    }

    public function testQueryErrorCodeIsReadFromErrorInfo(): void
    {
        $e = new PDOException('Table missing');
        $e->errorInfo = ['42S02', 1146, "Table 'x' doesn't exist"];

        self::assertSame([500, 'queryerror.html'], ErrorHandler::mapToErrorPage($e));
        self::assertSame('Database table does not exist.', ErrorHandler::errorPageData($e, 500, false)['message']);
    }

    /* ---------- Real MySQL/MariaDB server ---------- */

    private function configure(array $db, array $overrides = []): void
    {
        $db = array_merge($db, $overrides);
        Config::set('db.host', $db['host']);
        Config::set('db.port', $db['port']);
        Config::set('db.user', $db['user']);
        Config::set('db.pass', $db['pass']);
        Config::set('db.name', $db['name']);
    }

    public function testConnectsToMySql(): void
    {
        $this->configure(MySqlConfig::require($this));

        self::assertInstanceOf(Database::class, App::db());
        self::assertTrue(App::dbStatus()['connected']);
    }

    public function testUnknownDatabaseOnMySql(): void
    {
        $db = MySqlConfig::require($this);
        $this->configure($db, ['name' => $db['name'] . '_missing']);

        $status = App::dbStatus();

        self::assertTrue($status['configured']);
        self::assertFalse($status['connected']);
        self::assertSame(DatabaseConnectionException::REASON_UNKNOWN_DATABASE, $status['reason']);
        self::assertSame(1049, $status['code']);
    }

    public function testAccessDeniedOnMySql(): void
    {
        $this->configure(MySqlConfig::require($this), ['pass' => 'wrong-password-' . bin2hex(random_bytes(4))]);

        try {
            App::db();
            self::fail('Expected a connection failure.');
        } catch (DatabaseConnectionException $e) {
            self::assertSame(DatabaseConnectionException::REASON_ACCESS_DENIED, $e->reason());
            self::assertSame([500, 'dberror.html'], ErrorHandler::mapToErrorPage($e));
        }
    }

    public function testServerDownOnMySqlIsCachedForTheRequest(): void
    {
        // Port 1 is closed: connection refused
        $this->configure(MySqlConfig::require($this), ['host' => '127.0.0.1', 'port' => 1]);

        try {
            App::db();
            self::fail('Expected a connection failure.');
        } catch (DatabaseConnectionException $first) {
            self::assertTrue($first->isServerUnavailable());
        }

        try {
            App::db();
            self::fail('Expected a connection failure.');
        } catch (DatabaseConnectionException $second) {
            self::assertSame($first, $second);
        }
    }
}

final class TestModel extends Model
{
    public function database(): Database
    {
        return $this->db;
    }
}
