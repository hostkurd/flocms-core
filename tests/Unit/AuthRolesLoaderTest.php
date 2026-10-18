<?php
declare(strict_types=1);

namespace FloCMS\Core\Tests\Unit;

use FloCMS\Core\App;
use FloCMS\Core\Auth;
use FloCMS\Core\Config;
use FloCMS\Core\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Auth::loadRolesUsing() (2.4): role definitions supplied at runtime.
 */
final class AuthRolesLoaderTest extends TestCase
{
    private int $calls = 0;
    private string $log = '';

    protected function setUp(): void
    {
        $_SESSION = [];
        Config::$settings = [];
        Auth::clearRolesLoader();
        $this->calls = 0;
        $this->log = (string) tempnam(sys_get_temp_dir(), 'flo-auth-');
        App::$logger = new Logger($this->log);

        Config::set('roles', [0 => 'User', 1 => 'Editor', 2 => 'Admin', 3 => 'Super Admin']);
        Config::set('permissions', [0 => [], 1 => ['content.*'], 2 => ['content.*', 'users.manage', 'users.assign_role'], 3 => ['*']]);
        Config::set('admin_access_roles', ['1', '2', '3']);
    }

    protected function tearDown(): void
    {
        App::$logger = null;
        @unlink($this->log);
        Auth::clearRolesLoader();
        $_SESSION = [];
        Config::$settings = [];
    }

    private function loginAs(int $role): void
    {
        $_SESSION['role'] = $role;
        $_SESSION['admin_access'] = true;
    }

    /** @param array<string, mixed>|null $definitions */
    private function useLoader(?array $definitions): void
    {
        Auth::loadRolesUsing(function () use ($definitions): ?array {
            $this->calls++;
            return $definitions;
        });
    }

    public function testWithoutALoaderEverythingComesFromConfig(): void
    {
        $this->loginAs(1);

        self::assertTrue(Auth::can('content.posts.view'));
        self::assertFalse(Auth::can('users.manage'));
        self::assertSame([0 => 'User', 1 => 'Editor', 2 => 'Admin', 3 => 'Super Admin'], Auth::roles());
        self::assertSame(['1', '2', '3'], Auth::adminAccessRoles());
        self::assertSame(2, Auth::level(2));
        self::assertTrue(Auth::hasAdminAccess(1));
        self::assertFalse(Auth::hasAdminAccess(0));
    }

    public function testLoaderPermissionsReplaceConfig(): void
    {
        $this->useLoader(['permissions' => [1 => ['content.articles.*']]]);
        $this->loginAs(1);

        self::assertTrue(Auth::can('content.articles.update'));
        self::assertFalse(Auth::can('content.listings.view'));
    }

    public function testKeysTheLoaderLeavesOutKeepConfig(): void
    {
        $this->useLoader(['permissions' => [1 => ['content.articles.*']]]);

        self::assertSame(['1', '2', '3'], Auth::adminAccessRoles());
        self::assertSame('Editor', Auth::roles()[1]);
    }

    public function testNullResultKeepsConfig(): void
    {
        $this->useLoader(null);
        $this->loginAs(1);

        self::assertTrue(Auth::can('content.posts.view'));
        self::assertSame(1, $this->calls);
    }

    public function testLoaderRunsOncePerRequest(): void
    {
        $this->useLoader(['permissions' => [1 => ['content.*']]]);
        $this->loginAs(1);

        Auth::can('content.a');
        Auth::can('content.b');
        Auth::roles();
        Auth::level(1);

        self::assertSame(1, $this->calls);

        Auth::forgetLoadedRoles();
        Auth::can('content.c');
        self::assertSame(2, $this->calls);
    }

    public function testFailingLoaderFallsBackToConfig(): void
    {
        Auth::loadRolesUsing(function (): array {
            $this->calls++;
            throw new \RuntimeException('table missing');
        });
        $this->loginAs(2);

        self::assertTrue(Auth::can('users.manage'));
        self::assertTrue(Auth::can('content.x'));
        self::assertSame(1, $this->calls, 'A failure is not retried within the request');
        self::assertStringContainsString('The roles loader failed (table missing)', (string) file_get_contents($this->log));
    }

    public function testWrongResultTypeFallsBackToConfig(): void
    {
        Auth::loadRolesUsing(static fn (): string => 'nope');
        $this->loginAs(1);

        self::assertTrue(Auth::can('content.posts.view'));
        self::assertStringContainsString('returned string', (string) file_get_contents($this->log));
    }

    public function testNonArrayKeyIsIgnored(): void
    {
        $this->useLoader(['permissions' => 'oops', 'roles' => [1 => 'Writer']]);
        $this->loginAs(1);

        self::assertTrue(Auth::can('content.posts.view'));
        self::assertSame([1 => 'Writer'], Auth::roles());
    }

    public function testLoaderRolesAndAdminAccess(): void
    {
        $this->useLoader(['roles' => [0 => 'User', 4 => 'Author'], 'admin_access_roles' => [4]]);

        self::assertSame([0 => 'User', 4 => 'Author'], Auth::roles());
        self::assertSame(['4'], Auth::adminAccessRoles());
        self::assertTrue(Auth::hasAdminAccess('4'));
        self::assertFalse(Auth::hasAdminAccess(1));
        self::assertFalse(Auth::hasAdminAccess(null));
    }

    public function testHasAdminAccessIsNullWhenNothingIsConfigured(): void
    {
        Config::$settings = [];

        self::assertNull(Auth::adminAccessRoles());
        self::assertNull(Auth::hasAdminAccess(1));
    }

    public function testLevelsDefaultToTheRoleNumber(): void
    {
        $this->useLoader(['levels' => [4 => 1, 5 => 'x']]);

        self::assertSame(1, Auth::level(4));
        self::assertSame(5, Auth::level(5));
        self::assertSame(3, Auth::level(3));
    }

    public function testLevelsDecideRoleAssignment(): void
    {
        // Role 4 "Author" ranks with Editors (level 1); role 5 "Manager" ranks with Admins (level 2).
        $this->useLoader([
            'permissions' => [2 => ['users.manage', 'users.assign_role'], 5 => ['users.manage', 'users.assign_role']],
            'levels' => [4 => 1, 5 => 2],
        ]);
        $this->loginAs(2);

        self::assertTrue(Auth::canAssignRole(4), 'Level 1 is below level 2');
        self::assertTrue(Auth::canAssignRole(5), 'Same level may be assigned');
        self::assertFalse(Auth::canAssignRole(3));

        self::assertTrue(Auth::canManageUser(4));
        self::assertFalse(Auth::canManageUser(5), 'Same level cannot be managed');

        $this->loginAs(5);
        self::assertTrue(Auth::canAssignRole(2), 'Role 5 at level 2 may give role 2');
        self::assertFalse(Auth::canManageUser(2));
        self::assertTrue(Auth::canManageUser(1));
    }

    public function testRefreshUsesTheLoaderAdminAccess(): void
    {
        $this->useLoader(['admin_access_roles' => ['4']]);
        Config::set('auth.user_loader', static fn (int $id): array => ['id' => $id, 'role' => '4', 'status' => '1']);
        $_SESSION = ['isloggedin' => true, 'user_id' => 9, 'role' => '4', 'admin_access' => false];

        self::assertTrue(Auth::refresh());
        self::assertTrue($_SESSION['admin_access']);
    }

    public function testRefreshRemovesAdminAccessTheLoaderTakesAway(): void
    {
        $this->useLoader(['admin_access_roles' => ['2', '3']]);
        Config::set('auth.user_loader', static fn (int $id): array => ['id' => $id, 'role' => '1', 'status' => '1']);
        $_SESSION = ['isloggedin' => true, 'user_id' => 9, 'role' => '1', 'admin_access' => true];

        self::assertTrue(Auth::refresh());
        self::assertFalse($_SESSION['admin_access']);
    }

    public function testRefreshAcceptsAnyScalarRole(): void
    {
        $this->useLoader(['admin_access_roles' => ['4']]);
        Config::set('auth.user_loader', static fn (int $id): array => ['id' => $id, 'role' => 4.0, 'status' => '1']);
        $_SESSION = ['isloggedin' => true, 'user_id' => 9, 'role' => '4', 'admin_access' => false];

        self::assertTrue(Auth::refresh());
        self::assertTrue($_SESSION['admin_access'], 'a float role is read as its string, without a TypeError');
    }

    public function testPermissionMapKeepsOnlyStrings(): void
    {
        $this->useLoader(['permissions' => [1 => ['content.*', true, 7, null]]]);
        self::assertSame([1 => ['content.*']], Auth::permissionMap());
    }

    public function testChecksInsideTheLoaderSeeConfig(): void
    {
        $this->loginAs(1);
        Auth::loadRolesUsing(function (): array {
            $this->calls++;
            // A loader that checks a permission must not loop.
            return ['permissions' => [1 => Auth::can('content.x') ? ['content.x'] : []]];
        });

        self::assertTrue(Auth::can('content.x'));
        self::assertFalse(Auth::can('content.y'));
        self::assertSame(1, $this->calls);
    }

    public function testClearRolesLoaderRestoresConfig(): void
    {
        $this->useLoader(['permissions' => [1 => []]]);
        $this->loginAs(1);
        self::assertFalse(Auth::can('content.x'));

        Auth::clearRolesLoader();
        self::assertTrue(Auth::can('content.x'));
    }
}
