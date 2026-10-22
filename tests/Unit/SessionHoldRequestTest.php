<?php
declare(strict_types=1);

namespace FloCMS\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Held sign-ins (2.7) through App::run(): while held, admin requests go to the
 * hold's page, which is a guest admin action; front pages see a visitor.
 * Each request runs in a separate PHP process, like a real page request.
 */
final class SessionHoldRequestTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/flocms-hold-' . bin2hex(random_bytes(6));
        $files = [
            'public/conf_global.php' => "<?php\n",
            'lang/en.php' => "<?php\nreturn [];\n",
            'templates/default/layouts/default.html' => '[site]<?= $content ?>',
            'templates/default/layouts/admin.html' => '[admin]<?= $content ?>',
            'views/page.html' => '<?= $text ?>',
            'request.php' => self::requestScript(),
        ];
        foreach ($files as $path => $content) {
            $file = self::$root . '/' . $path;
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            file_put_contents($file, $content);
        }
    }

    public static function tearDownAfterClass(): void
    {
        exec('rm -rf ' . escapeshellarg(self::$root));
    }

    /** @return array{status: int, body: string, location: ?string, session: array<string, mixed>} */
    private static function request(string $uri, array $options = []): array
    {
        $command = [PHP_BINARY, self::$root . '/request.php', dirname(__DIR__, 2), $uri, json_encode($options)];
        exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $output, $code);
        $result = json_decode(implode("\n", $output), true);
        self::assertIsArray($result, implode("\n", $output));

        return $result;
    }

    /** @return array<string, mixed> */
    private static function signedIn(): array
    {
        return ['isloggedin' => true, 'user_id' => 7, 'role' => '2', 'admin_access' => true];
    }

    public function testTheFirstAdminRequestAfterSignInGoesToTheHoldsPage(): void
    {
        $result = self::request('/admin', ['session' => self::signedIn(), 'hold' => 'two-factor']);

        self::assertSame('https://example.test/en/admin/two-factor', $result['location']);
        self::assertFalse($result['session']['isloggedin']);
        self::assertSame('two-factor', $result['session']['auth_held']['path']);
    }

    public function testTheHoldsPageOpens(): void
    {
        $held = self::request('/admin', ['session' => self::signedIn(), 'hold' => 'two-factor'])['session'];
        $result = self::request('/admin/two-factor', ['session' => $held, 'hold' => 'two-factor']);

        self::assertNull($result['location']);
        self::assertSame('[admin]step:7', $result['body']);
    }

    public function testEveryOtherAdminPageGoesToTheHoldsPage(): void
    {
        $held = self::request('/admin', ['session' => self::signedIn(), 'hold' => 'two-factor'])['session'];

        foreach (['/admin/reports', '/admin/users/login', '/admin/two-factor/other'] as $uri) {
            self::assertSame('https://example.test/en/admin/two-factor', self::request($uri, ['session' => $held, 'hold' => 'two-factor'])['location'], $uri);
        }
    }

    public function testOtherGuestActionsAndLogoutStillOpen(): void
    {
        $held = self::request('/admin', ['session' => self::signedIn(), 'hold' => 'two-factor'])['session'];

        self::assertSame('[admin]forgot', self::request('/admin/password/forgot', ['session' => $held, 'hold' => 'two-factor'])['body']);
        $logout = self::request('/admin/users/logout', ['session' => $held, 'hold' => 'two-factor']);
        self::assertSame('[admin]logged-out', $logout['body']);
        self::assertSame([], $logout['session']);
    }

    public function testFrontPagesSeeAVisitorWhileHeld(): void
    {
        $result = self::request('/', ['session' => self::signedIn(), 'hold' => 'two-factor']);

        self::assertSame('[site]home:visitor', $result['body']);
        self::assertSame('two-factor', $result['session']['auth_held']['path']);
    }

    public function testReleasingLetsThePanelOpen(): void
    {
        $held = self::request('/admin', ['session' => self::signedIn(), 'hold' => 'two-factor'])['session'];
        $released = self::request('/admin/two-factor/done', ['session' => $held, 'hold' => 'two-factor'])['session'];

        self::assertTrue($released['isloggedin']);
        $result = self::request('/admin/reports', ['session' => $released, 'hold' => 'two-factor']);
        self::assertNull($result['location']);
        self::assertSame('[admin]reports', $result['body']);
    }

    public function testAHeldPathThatIsNotAGuestActionEndsTheSession(): void
    {
        $result = self::request('/admin', ['session' => self::signedIn(), 'hold' => 'reports']);

        self::assertSame('https://example.test/en/admin/users/login/', $result['location']);
        self::assertSame([], $result['session']);
    }

    public function testWithoutHoldsTheSessionIsUntouched(): void
    {
        $result = self::request('/admin/reports', ['session' => self::signedIn()]);

        self::assertSame('[admin]reports', $result['body']);
        self::assertTrue($result['session']['isloggedin']);
    }

    private static function requestScript(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);

[, $core, $uri, $options] = $argv;
$options = json_decode($options, true);

// Router::redirect() calls header() unqualified: record the Location.
$GLOBALS['location'] = null;
eval('namespace FloCMS\Core; function header(string $line, bool $replace = true, int $code = 0): void {
    if (stripos($line, "Location: ") === 0) { $GLOBALS["location"] = substr($line, 10); }
}');

require $core . '/vendor/autoload.php';

define('ROOT', __DIR__);
define('DS', DIRECTORY_SEPARATOR);
define('VIEWS_PATH', ROOT . '/views');
define('TEMPLATES_PATH', ROOT . '/templates');
define('SITE_URI', 'https://example.test');
define('ACTIVE_LANG', 'en');

use FloCMS\Core\App;
use FloCMS\Core\Auth;
use FloCMS\Core\Config;
use FloCMS\Core\HttpException;
use FloCMS\Core\Session;

eval('namespace FloCMS\Controllers; final class TwoFactorController extends \FloCMS\Core\Controller {
    public function admin_index(): string { $this->data["text"] = "step:" . \FloCMS\Core\Auth::heldUserId(); return VIEWS_PATH . "/page.html"; }
    public function admin_done(): string { \FloCMS\Core\Auth::releaseHold(); $this->data["text"] = "done"; return VIEWS_PATH . "/page.html"; }
    public function admin_other(): string { $this->data["text"] = "other"; return VIEWS_PATH . "/page.html"; }
}');
eval('namespace FloCMS\Controllers; final class PasswordController extends \FloCMS\Core\Controller {
    public function admin_forgot(): string { $this->data["text"] = "forgot"; return VIEWS_PATH . "/page.html"; }
}');
eval('namespace FloCMS\Controllers; final class ReportsController extends \FloCMS\Core\Controller {
    public function admin_index(): string { $this->data["text"] = "reports"; return VIEWS_PATH . "/page.html"; }
}');
eval('namespace FloCMS\Controllers; final class PagesController extends \FloCMS\Core\Controller {
    public function index(): string { $this->data["text"] = "home:" . (\FloCMS\Core\Session::get("isloggedin") ? "member" : "visitor"); return VIEWS_PATH . "/page.html"; }
}');
eval('namespace FloCMS\Controllers; final class UsersController extends \FloCMS\Core\Controller {
    public function admin_login(): string { $this->data["text"] = "login"; return VIEWS_PATH . "/page.html"; }
    public function admin_logout(): string { \FloCMS\Core\Session::destroy(); $this->data["text"] = "logged-out"; return VIEWS_PATH . "/page.html"; }
}');

App::allowGuestAdminActions('two-factor', ['admin_index', 'admin_done']);
App::allowGuestAdminActions('password', ['admin_forgot']);
if (isset($options['hold'])) {
    $path = $options['hold'];
    Auth::holdSessionsUsing(static fn (array $user): ?string => $path);
}

Config::set('auth.user_loader', static fn (int $id): ?array => $id === 7 ? ['id' => 7, 'role' => '2', 'status' => '1'] : null);
Config::set('admin_access_roles', ['1', '2', '3']);
$_SESSION = $options['session'] ?? [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_ENV['DEFAULT_LANG'] = 'en';
Config::set('routes', ['default' => '', 'admin' => 'admin_']);
Config::set('default_route', 'default');
Config::set('default_controller', 'pages');
Config::set('default_action', 'index');
Config::set('languages', ['en']);
Config::set('default_language', 'en');
Config::set('view.cache', false);

$status = 200;
ob_start();
register_shutdown_function(static function () use (&$status): void {
    $body = (string) ob_get_clean();
    echo json_encode(['status' => $status, 'body' => $body, 'location' => $GLOBALS['location'], 'session' => $_SESSION]);
});
try {
    App::run($uri);
    $status = http_response_code() ?: 200;
} catch (HttpException $e) {
    $status = $e->status;
}
PHP;
    }
}
