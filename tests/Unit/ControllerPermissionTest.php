<?php
declare(strict_types=1);

namespace FloCMS\Core\Tests\Unit;

use FloCMS\Core\App;
use FloCMS\Core\Config;
use FloCMS\Core\Controller;
use FloCMS\Core\HttpException;
use PHPUnit\Framework\TestCase;

final class ControllerPermissionTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        Config::$settings = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        Config::$settings = [];
    }

    private function controller(array $permissions): Controller
    {
        return new class ($permissions) extends Controller {
            public function __construct(array $permissions)
            {
                // Skip parent constructor: it needs the router.
                $this->actionPermissions = $permissions;
            }
        };
    }

    public function testNoPermissionsMeansNoCheck(): void
    {
        self::assertNull($this->controller([])->permissionFor('admin_index'));
    }

    public function testExactActionWinsOverWildcard(): void
    {
        $c = $this->controller(['*' => 'users.manage', 'admin_profile' => null, 'admin_edit' => 'users.edit']);

        self::assertNull($c->permissionFor('admin_profile'));
        self::assertSame('users.edit', $c->permissionFor('admin_edit'));
        self::assertSame('users.manage', $c->permissionFor('admin_index'));
    }

    public function testActionLookupIsCaseInsensitive(): void
    {
        $c = $this->controller(['admin_Login' => null, '*' => 'users.manage']);

        self::assertNull($c->permissionFor('admin_login'));
    }

    public function testAppDeniesActionWithoutPermission(): void
    {
        Config::set('permissions', [1 => ['content.*']]);
        $_SESSION['role'] = 1;

        $this->expectException(HttpException::class);
        AppProbe::check($this->controller(['*' => 'users.manage']), 'admin_index');
    }

    public function testAppAllowsPermittedAndUnmappedActions(): void
    {
        Config::set('permissions', [2 => ['users.manage']]);
        $_SESSION['role'] = 2;

        AppProbe::check($this->controller(['*' => 'users.manage']), 'admin_index');
        AppProbe::check($this->controller([]), 'admin_index');
        $this->addToAssertionCount(2);
    }
}

final class AppProbe extends App
{
    public static function check(Controller $controller, string $method): void
    {
        self::assertActionAllowed($controller, $method);
    }
}
