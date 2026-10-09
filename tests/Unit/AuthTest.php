<?php
declare(strict_types=1);

namespace FloCMS\Core\Tests\Unit;

use FloCMS\Core\Auth;
use FloCMS\Core\Config;
use FloCMS\Core\HttpException;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    private const MAP = [
        0 => [],
        1 => ['content.*'],
        2 => ['content.*', 'users.manage', 'users.assign_role'],
        3 => ['*'],
    ];

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

    private function loginAs(?int $role, bool $adminAccess = true, bool $withMap = true): void
    {
        $_SESSION['role'] = $role;
        $_SESSION['admin_access'] = $adminAccess;

        if ($withMap) {
            Config::set('permissions', self::MAP);
        }
    }

    public function testRoleIsNullWhenNotLoggedIn(): void
    {
        self::assertNull(Auth::role());
    }

    public function testRoleCastsSessionStringToInt(): void
    {
        $_SESSION['role'] = '2';

        self::assertSame(2, Auth::role());
    }

    public function testExactPermissionMatches(): void
    {
        $this->loginAs(2);

        self::assertTrue(Auth::can('users.manage'));
        self::assertFalse(Auth::can('users.manage_any'));
    }

    public function testPrefixWildcardMatches(): void
    {
        $this->loginAs(1);

        self::assertTrue(Auth::can('content.edit'));
        self::assertTrue(Auth::can('content.pages.delete'));
        self::assertFalse(Auth::can('contentx.edit'));
        self::assertFalse(Auth::can('users.manage'));
    }

    public function testGlobalWildcardMatchesEverything(): void
    {
        $this->loginAs(3);

        self::assertTrue(Auth::can('users.manage_any'));
        self::assertTrue(Auth::can('anything.at.all'));
    }

    public function testUnknownRoleIsDenied(): void
    {
        $this->loginAs(9);

        self::assertFalse(Auth::can('content.edit'));
    }

    public function testGuestIsDeniedWhenMapConfigured(): void
    {
        $this->loginAs(null, true);

        self::assertFalse(Auth::can('content.edit'));
    }

    public function testFallsBackToAdminAccessWhenMapNotConfigured(): void
    {
        $this->loginAs(1, true, false);
        self::assertTrue(Auth::can('users.manage'));

        $this->loginAs(0, false, false);
        self::assertFalse(Auth::can('users.manage'));
    }

    public function testAuthorizeThrows403(): void
    {
        $this->loginAs(1);

        try {
            Auth::authorize('users.manage');
            self::fail('Expected HttpException');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
    }

    public function testAuthorizePassesWhenAllowed(): void
    {
        $this->loginAs(2);

        Auth::authorize('users.manage');
        $this->addToAssertionCount(1);
    }

    public function testAdminCanAssignRolesUpToOwn(): void
    {
        $this->loginAs(2);

        self::assertTrue(Auth::canAssignRole(0));
        self::assertTrue(Auth::canAssignRole(2));
        self::assertFalse(Auth::canAssignRole(3));
    }

    public function testEditorCannotAssignAnyRole(): void
    {
        $this->loginAs(1);

        self::assertFalse(Auth::canAssignRole(0));
        self::assertFalse(Auth::canAssignRole(1));
    }

    public function testSuperAdminCanAssignSuperAdmin(): void
    {
        $this->loginAs(3);

        self::assertTrue(Auth::canAssignRole(3));
    }

    public function testAdminCanManageOnlyStrictlyLowerRoles(): void
    {
        $this->loginAs(2);

        self::assertTrue(Auth::canManageUser(0));
        self::assertTrue(Auth::canManageUser(1));
        self::assertFalse(Auth::canManageUser(2));
        self::assertFalse(Auth::canManageUser(3));
    }

    public function testSuperAdminCanManagePeers(): void
    {
        $this->loginAs(3);

        self::assertTrue(Auth::canManageUser(3));
    }

    public function testEditorCannotManageUsers(): void
    {
        $this->loginAs(1);

        self::assertFalse(Auth::canManageUser(0));
    }

    public function testGuestCannotAssignOrManage(): void
    {
        $this->loginAs(null, true, false);

        self::assertFalse(Auth::canAssignRole(0));
        self::assertFalse(Auth::canManageUser(0));
    }
}
