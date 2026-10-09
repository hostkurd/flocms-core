<?php
declare(strict_types=1);

namespace FloCMS\Core\Tests\Unit;

use FloCMS\Core\Auth;
use FloCMS\Core\Config;
use PHPUnit\Framework\TestCase;

final class AuthRefreshTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $users = [];
    private int $loads = 0;

    protected function setUp(): void
    {
        Config::$settings = [];
        $this->users = [7 => ['id' => 7, 'role' => '2', 'status' => '1']];
        $this->loads = 0;

        Config::set('admin_access_roles', ['1', '2', '3']);
        Config::set('auth.user_loader', function (int $id) {
            $this->loads++;
            return $this->users[$id] ?? false;
        });

        $_SESSION = [
            'isloggedin' => true,
            'user_id' => 7,
            'role' => '2',
            'admin_access' => true,
            'email' => 'admin@example.com',
        ];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        Config::$settings = [];
    }

    public function testActiveUserKeepsSession(): void
    {
        self::assertTrue(Auth::refresh());
        self::assertTrue($_SESSION['admin_access']);
        self::assertSame('2', $_SESSION['role']);
        self::assertSame(1, $this->loads);
    }

    public function testSuspendedUserLosesSession(): void
    {
        $this->users[7]['status'] = '3';

        self::assertFalse(Auth::refresh());
        self::assertSame([], $_SESSION);
    }

    public function testDeletedUserLosesSession(): void
    {
        unset($this->users[7]);

        self::assertFalse(Auth::refresh());
        self::assertSame([], $_SESSION);
    }

    public function testDemotedUserGetsNewRoleImmediately(): void
    {
        $this->users[7]['role'] = '1';

        self::assertTrue(Auth::refresh());
        self::assertSame('1', $_SESSION['role']);
        self::assertSame(1, Auth::role());
        self::assertTrue($_SESSION['admin_access']);
    }

    public function testUserDemotedBelowAdminLosesAdminAccess(): void
    {
        $this->users[7]['role'] = 0;

        self::assertTrue(Auth::refresh());
        self::assertFalse($_SESSION['admin_access']);
    }

    public function testObjectRowsAreAccepted(): void
    {
        $this->users[7] = (object) ['role' => '3', 'status' => 1];
        Config::set('auth.user_loader', fn (int $id) => $this->users[$id] ?? null);

        self::assertTrue(Auth::refresh());
        self::assertSame('3', $_SESSION['role']);
    }

    public function testSessionFromBeforeUpgradeWithoutUserIdEnds(): void
    {
        unset($_SESSION['user_id']);

        self::assertFalse(Auth::refresh());
        self::assertSame([], $_SESSION);
    }

    public function testWithoutLoaderNothingChanges(): void
    {
        Config::set('auth.user_loader', null);
        $this->users[7]['status'] = '3';
        $before = $_SESSION;

        self::assertTrue(Auth::refresh());
        self::assertSame($before, $_SESSION);
    }

    public function testGuestsAreIgnored(): void
    {
        $_SESSION = [];

        self::assertTrue(Auth::refresh());
        self::assertSame(0, $this->loads);
    }

    public function testIntervalSkipsReloadUntilDue(): void
    {
        Config::set('auth.refresh_interval', 300);

        self::assertTrue(Auth::refresh());
        $this->users[7]['status'] = '3';
        self::assertTrue(Auth::refresh());
        self::assertSame(1, $this->loads);

        $_SESSION['auth_checked_at'] = time() - 301;
        self::assertFalse(Auth::refresh());
    }

    public function testCustomActiveStatus(): void
    {
        Config::set('auth.active_status', 'active');
        $this->users[7]['status'] = 'active';

        self::assertTrue(Auth::refresh());
    }
}
