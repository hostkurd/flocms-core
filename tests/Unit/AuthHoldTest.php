<?php
declare(strict_types=1);

namespace FloCMS\Core\Tests\Unit;

use FloCMS\Core\Auth;
use FloCMS\Core\Config;
use PHPUnit\Framework\TestCase;

/**
 * Auth::holdSessionsUsing() (2.7): a fresh sign-in can be held at one admin
 * page until a step is done (a two-factor code, for example).
 */
final class AuthHoldTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $users = [];

    /** @var list<string> */
    private array $asked = [];

    protected function setUp(): void
    {
        Config::$settings = [];
        Auth::clearSessionHolds();
        Auth::clearSessionChecks();
        $this->users = [7 => ['id' => 7, 'role' => '2', 'status' => '1', 'two_factor' => true]];
        $this->asked = [];
        Config::set('admin_access_roles', ['1', '2', '3']);
        Config::set('auth.user_loader', fn (int $id) => $this->users[$id] ?? false);
        $_SESSION = self::signedIn();
    }

    protected function tearDown(): void
    {
        Auth::clearSessionHolds();
        Auth::clearSessionChecks();
        $_SESSION = [];
        Config::$settings = [];
    }

    /** @return array<string, mixed> */
    private static function signedIn(): array
    {
        return ['isloggedin' => true, 'user_id' => 7, 'role' => '2', 'admin_access' => true, 'username' => 'sara', 'email' => 'sara@example.com', 'fullname' => 'Sara', 'theme' => 'dark'];
    }

    private function twoFactorHold(): void
    {
        Auth::holdSessionsUsing(function (array $user): ?string {
            $this->asked[] = 'two-factor:' . $user['id'];
            return !empty($user['two_factor']) ? 'two-factor' : null;
        });
    }

    public function testWithoutHoldsNothingChanges(): void
    {
        self::assertNull(Auth::applyHolds());
        self::assertSame(self::signedIn(), $_SESSION);
    }

    public function testAHoldParksTheSignIn(): void
    {
        $this->twoFactorHold();

        self::assertSame('two-factor', Auth::applyHolds());
        self::assertFalse($_SESSION['isloggedin']);
        self::assertFalse($_SESSION['admin_access']);
        foreach (['user_id', 'role', 'username', 'email', 'fullname'] as $key) {
            self::assertArrayNotHasKey($key, $_SESSION, $key);
        }
        self::assertSame('dark', $_SESSION['theme'], 'keys that are not sign-in keys stay');
        self::assertSame('two-factor', Auth::heldPath());
        self::assertSame(7, Auth::heldUserId());
        self::assertNull(Auth::role());
    }

    public function testAHeldSessionStaysHeldWithoutAskingAgain(): void
    {
        $this->twoFactorHold();
        Auth::applyHolds();

        self::assertSame('two-factor', Auth::applyHolds());
        self::assertSame(['two-factor:7'], $this->asked);
    }

    public function testReleasingRestoresTheSignIn(): void
    {
        $this->twoFactorHold();
        Auth::applyHolds();

        self::assertNull(Auth::releaseHold());
        self::assertTrue($_SESSION['isloggedin']);
        self::assertTrue($_SESSION['admin_access']);
        self::assertSame(7, $_SESSION['user_id']);
        self::assertSame('sara@example.com', $_SESSION['email']);
        self::assertNull(Auth::heldPath());
        self::assertNull(Auth::heldUserId());
    }

    public function testHoldsAreAskedOncePerSignIn(): void
    {
        $this->twoFactorHold();
        Auth::applyHolds();
        Auth::releaseHold();

        self::assertNull(Auth::applyHolds());
        self::assertNull(Auth::applyHolds());
        self::assertSame(['two-factor:7'], $this->asked);
    }

    public function testASignInWithoutAHoldIsNotAskedAgain(): void
    {
        $this->users[7]['two_factor'] = false;
        $this->twoFactorHold();

        self::assertNull(Auth::applyHolds());
        self::assertNull(Auth::applyHolds());
        self::assertSame(['two-factor:7'], $this->asked);
        self::assertTrue($_SESSION['isloggedin']);
    }

    public function testANewSignInIsAskedAgain(): void
    {
        $this->users[8] = ['id' => 8, 'role' => '2', 'status' => '1', 'two_factor' => false];
        $this->twoFactorHold();
        Auth::applyHolds();
        Auth::releaseHold();

        // Another person signs in in the same browser session.
        $_SESSION['user_id'] = 8;
        self::assertNull(Auth::applyHolds());
        self::assertSame(['two-factor:7', 'two-factor:8'], $this->asked);
    }

    public function testSigningInAgainWhileHeldDropsTheOldHold(): void
    {
        $this->users[8] = ['id' => 8, 'role' => '2', 'status' => '1', 'two_factor' => false];
        $this->twoFactorHold();
        Auth::applyHolds();

        $_SESSION = array_merge($_SESSION, ['isloggedin' => true, 'user_id' => 8, 'admin_access' => true]);
        self::assertNull(Auth::applyHolds());
        self::assertNull(Auth::heldPath());
        self::assertSame(8, $_SESSION['user_id']);
    }

    public function testTheNextHoldIsAskedAfterARelease(): void
    {
        $this->twoFactorHold();
        Auth::holdSessionsUsing(function (array $user): ?string {
            $this->asked[] = 'terms';
            return 'account/terms';
        });

        self::assertSame('two-factor', Auth::applyHolds());
        self::assertSame('account/terms', Auth::releaseHold());
        self::assertSame('account/terms', Auth::heldPath());
        self::assertNull(Auth::releaseHold());
        self::assertSame(['two-factor:7', 'terms'], $this->asked);
        self::assertTrue($_SESSION['isloggedin']);
    }

    public function testCancellingEndsTheSession(): void
    {
        $this->twoFactorHold();
        Auth::applyHolds();

        Auth::cancelHold();
        self::assertSame([], $_SESSION);
    }

    public function testCancelWithoutAHoldKeepsTheSession(): void
    {
        Auth::cancelHold();
        self::assertSame(self::signedIn(), $_SESSION);
    }

    public function testAnOldHoldEnds(): void
    {
        Config::set('auth.hold_lifetime', 60);
        $this->twoFactorHold();
        Auth::applyHolds();
        $_SESSION[Auth::HELD_KEY]['since'] = time() - 61;

        self::assertNull(Auth::applyHolds());
        self::assertNull(Auth::heldPath());
        self::assertArrayNotHasKey('user_id', $_SESSION);
        self::assertTrue(\FloCMS\Core\Session::hasFlash());
    }

    public function testAHoldThatThrowsEndsTheSession(): void
    {
        Auth::holdSessionsUsing(static function (): ?string {
            throw new \RuntimeException('store down');
        });

        $log = ini_set('error_log', '/dev/null');
        try {
            self::assertNull(Auth::applyHolds());
        } finally {
            ini_set('error_log', (string) $log);
        }
        self::assertSame([], $_SESSION);
    }

    public function testAnInvalidPathEndsTheSession(): void
    {
        Auth::holdSessionsUsing(static fn (): string => '../users');

        $log = ini_set('error_log', '/dev/null');
        try {
            self::assertNull(Auth::applyHolds());
        } finally {
            ini_set('error_log', (string) $log);
        }
        self::assertSame([], $_SESSION);
    }

    public function testReleasingASuspendedUserEndsTheSession(): void
    {
        $this->twoFactorHold();
        Auth::applyHolds();
        $this->users[7]['status'] = '3';

        self::assertNull(Auth::releaseHold());
        self::assertSame([], $_SESSION);
    }

    public function testVisitorsAreNotAsked(): void
    {
        $this->twoFactorHold();
        $_SESSION = [];

        self::assertNull(Auth::applyHolds());
        self::assertSame([], $this->asked);
    }

    public function testParkedKeysCanBeConfigured(): void
    {
        Config::set('auth.hold_keys', ['isloggedin', 'admin_access', 'user_id', 'member_name']);
        $_SESSION['member_name'] = 'Sara';
        $this->twoFactorHold();
        Auth::applyHolds();

        self::assertArrayNotHasKey('member_name', $_SESSION);
        self::assertSame('sara@example.com', $_SESSION['email']);
        Auth::releaseHold();
        self::assertSame('Sara', $_SESSION['member_name']);
    }
}
