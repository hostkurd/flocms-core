<?php
declare(strict_types=1);

namespace FloCMS\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * App::resolveControllersUsing(): packages can supply page controllers for
 * URLs the application has no controller for. Each request runs App::run()
 * in a separate PHP process, like a real page request.
 */
final class ControllerResolverTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/flocms-resolver-' . bin2hex(random_bytes(6));
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

    /** @return array{status: int, body: string, redirected: bool, calls: list<string>} */
    private static function request(string $uri, array $options = []): array
    {
        $command = [PHP_BINARY, self::$root . '/request.php', dirname(__DIR__, 2), $uri, json_encode($options)];
        exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $output, $code);
        $result = json_decode(implode("\n", $output), true);
        self::assertIsArray($result, implode("\n", $output));

        return $result;
    }

    public function testWithoutResolversAnUnknownControllerIs404(): void
    {
        self::assertSame(404, self::request('/properties')['status']);
    }

    public function testAResolverSuppliesTheController(): void
    {
        $result = self::request('/properties', ['resolver' => 'resource']);

        self::assertSame(200, $result['status']);
        self::assertSame('[site]resource:properties:index', $result['body']);
        self::assertSame(['properties|default'], $result['calls']);
    }

    public function testActionsAndParametersReachTheResolvedController(): void
    {
        $result = self::request('/properties/edit/5', ['resolver' => 'resource']);

        self::assertSame(200, $result['status']);
        self::assertSame('[site]resource:properties:edit:5', $result['body']);
    }

    public function testAdminRoutesUseTheAdminPrefixAndLayout(): void
    {
        $result = self::request('/admin/properties/create', ['resolver' => 'resource', 'admin' => true]);

        self::assertSame(200, $result['status']);
        self::assertSame('[admin]resource:properties:admin_create', $result['body']);
        self::assertSame(['properties|admin'], $result['calls']);
    }

    public function testTheAdminGateRunsBeforeAnyResolver(): void
    {
        $result = self::request('/admin/properties', ['resolver' => 'resource']);

        // Router::redirect() to the login page exits before the controller is resolved
        self::assertTrue($result['redirected']);
        self::assertSame([], $result['calls']);
    }

    public function testApplicationControllersAlwaysWin(): void
    {
        $result = self::request('/pages', ['resolver' => 'resource']);

        self::assertSame('[site]app:pages', $result['body']);
        self::assertSame([], $result['calls']);
    }

    public function testResolversAreAskedInOrderUntilOneAnswers(): void
    {
        $result = self::request('/properties', ['resolver' => ['pass', 'resource']]);

        self::assertSame(200, $result['status']);
        self::assertSame(['properties|default', 'properties|default'], $result['calls']);
    }

    public function testInvalidResolverResultsAre404(): void
    {
        foreach (['missing-class', 'not-a-controller', 'abstract', 'not-a-string'] as $resolver) {
            self::assertSame(404, self::request('/properties', ['resolver' => $resolver])['status'], $resolver);
        }
    }

    public function testOnlyDeclaredPublicActionsOfAResolvedControllerAreReachable(): void
    {
        foreach (['/properties/helper', '/properties/getdata', '/properties/setrequest', '/properties/missing'] as $uri) {
            self::assertSame(404, self::request($uri, ['resolver' => 'resource'])['status'], $uri);
        }
    }

    public function testActionPermissionsOfAResolvedControllerAreEnforced(): void
    {
        self::assertSame(403, self::request('/properties/delete/5', ['resolver' => 'resource'])['status']);
    }

    public function testClearRemovesResolvers(): void
    {
        self::assertSame(404, self::request('/properties', ['resolver' => 'resource', 'clear' => true])['status']);
    }

    public function testAuthenticatedUserWithoutAdminAccessCanLogOut(): void
    {
        $result = self::request('/admin/users/logout', ['session' => [
            'isloggedin' => true, 'user_id' => 7, 'role' => 0, 'admin_access' => false,
        ]]);

        self::assertSame(200, $result['status']);
        self::assertFalse($result['redirected']);
        self::assertSame('[admin]logged-out', $result['body']);
        self::assertSame([], $result['session']);
    }

    public function testLogoutExceptionDoesNotOpenOtherAdminActions(): void
    {
        foreach (['/admin/users', '/admin/properties/logout'] as $uri) {
            $result = self::request($uri, [
                'session' => ['isloggedin' => true, 'admin_access' => false],
                'resolver' => 'resource',
            ]);

            self::assertTrue($result['redirected'], $uri);
            self::assertSame([], $result['calls'], $uri);
        }
    }

    public function testGuestLogoutStillPassesThroughTheLoginGate(): void
    {
        $result = self::request('/admin/users/logout', ['session' => []]);

        self::assertTrue($result['redirected']);
        self::assertSame([], $result['calls']);
    }

    public function testPostLogoutStillRequiresCsrf(): void
    {
        $session = ['isloggedin' => true, 'admin_access' => false, '_csrf_token' => 'logout-token'];
        $missing = self::request('/admin/users/logout', ['session' => $session, 'method' => 'POST']);
        self::assertSame(419, $missing['status']);
        self::assertTrue($missing['session']['isloggedin']);

        $valid = self::request('/admin/users/logout', [
            'session' => $session, 'method' => 'POST', 'post' => ['_token' => 'logout-token'],
        ]);
        self::assertSame(200, $valid['status']);
        self::assertSame([], $valid['session']);
    }

    public function testExplicitResponsesBypassTheLayout(): void
    {
        $html = self::request('/properties/fragment', ['resolver' => 'resource']);
        self::assertSame(202, $html['status']);
        self::assertSame('<p>fragment</p>', $html['body']);
        $json = self::request('/properties/json', ['resolver' => 'resource']);
        self::assertSame(['ok' => true], json_decode($json['body'], true));
        $empty = self::request('/properties/empty', ['resolver' => 'resource']);
        self::assertSame(204, $empty['status']);
        self::assertSame('', $empty['body']);
    }

    public function testExplicitResponsesKeepPermissionsAndCsrfChecks(): void
    {
        self::assertSame(403, self::request('/properties/secured', ['resolver' => 'resource'])['status']);
        self::assertSame(419, self::request('/properties/fragment', ['resolver' => 'resource', 'method' => 'POST'])['status']);
        self::assertSame(419, self::request('/properties/fragment', ['resolver' => 'resource', 'method' => 'POST', 'post' => ['_token' => ['invalid']]])['status']);
        $valid = self::request('/properties/fragment', ['resolver' => 'resource', 'method' => 'POST',
            'session' => ['_csrf_token' => 'tok'], 'post' => ['_token' => 'tok']]);
        self::assertSame('<p>fragment</p>', $valid['body']);
    }

    public function testHeadResponsesKeepStatusWithoutABody(): void
    {
        $result = self::request('/properties/fragment', ['resolver' => 'resource', 'method' => 'HEAD']);
        self::assertSame(202, $result['status']);
        self::assertSame('', $result['body']);
    }

    public function testGuestAdminActionsOpenOnlyTheListedMethods(): void
    {
        $guest = ['resolver' => 'resource', 'guest' => [['properties', ['admin_forgot']]]];

        $open = self::request('/admin/properties/forgot/abc', $guest);
        self::assertSame(200, $open['status']);
        self::assertFalse($open['redirected']);
        self::assertSame('[admin]resource:properties:admin_forgot:abc', $open['body']);

        $closed = self::request('/admin/properties/create', $guest);
        self::assertTrue($closed['redirected'], 'another action of the same controller');
        self::assertSame([], $closed['calls']);
    }

    public function testGuestAdminActionsAreMatchedByControllerAndMethod(): void
    {
        $result = self::request('/admin/properties/forgot', ['resolver' => 'resource', 'guest' => [['accounts', ['admin_forgot']]]]);
        self::assertTrue($result['redirected']);

        $upper = self::request('/admin/properties/forgot', ['resolver' => 'resource', 'guest' => [['Properties', ['ADMIN_FORGOT']]]]);
        self::assertSame(200, $upper['status'], 'names are compared in lower case');
    }

    public function testGuestAdminActionsStillRequireCsrfOnPost(): void
    {
        $guest = ['resolver' => 'resource', 'guest' => [['properties', ['admin_forgot']]], 'method' => 'POST'];
        self::assertSame(419, self::request('/admin/properties/forgot', $guest)['status']);

        $valid = self::request('/admin/properties/forgot', $guest + ['session' => ['_csrf_token' => 'tok'], 'post' => ['_token' => 'tok']]);
        self::assertSame(200, $valid['status']);
    }

    public function testClearGuestAdminActionsClosesThemAgain(): void
    {
        $result = self::request('/admin/properties/forgot', [
            'resolver' => 'resource', 'guest' => [['properties', ['admin_forgot']]], 'clearGuest' => true,
        ]);
        self::assertTrue($result['redirected']);
    }

    public function testGuestAdminActionNamesAreValidated(): void
    {
        foreach ([['../x', ['admin_a']], ['ok', ['admin a']], ['ok', ['']], ['ok', [5]]] as [$controller, $methods]) {
            try {
                \FloCMS\Core\App::allowGuestAdminActions($controller, $methods);
                self::fail('accepted ' . json_encode([$controller, $methods]));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        \FloCMS\Core\App::clearGuestAdminActions();
        self::assertFalse(\FloCMS\Core\App::isGuestAdminAction('ok', 'admin_a'));
    }

    private static function requestScript(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);

[, $core, $uri, $options] = $argv;
$options = json_decode($options, true);
require $core . '/vendor/autoload.php';

define('ROOT', __DIR__);
define('DS', DIRECTORY_SEPARATOR);
define('VIEWS_PATH', ROOT . '/views');
define('TEMPLATES_PATH', ROOT . '/templates');
define('SITE_URI', 'https://example.test');
define('ACTIVE_LANG', 'en');

use FloCMS\Core\App;
use FloCMS\Core\Config;
use FloCMS\Core\Controller;
use FloCMS\Core\HttpException;
use FloCMS\Core\Router;

final class ResourceController extends Controller
{
    protected array $actionPermissions = ['delete' => 'resources.delete', 'secured' => 'resources.delete'];

    private function render(string $action, array $params = []): string
    {
        $this->data['text'] = implode(':', ['resource', App::getRouter()->getController(), $action, ...$params]);
        return VIEWS_PATH . '/page.html';
    }

    public function index(): string { return $this->render('index'); }
    public function edit(): string { return $this->render('edit', $this->params); }
    public function delete(): string { return $this->render('delete', $this->params); }
    public function admin_create(): string { return $this->render('admin_create'); }
    public function admin_forgot(): string { return $this->render('admin_forgot', $this->params); }
    public function admin_logout(): string { return $this->render('admin_logout'); }
    public function fragment(): \FloCMS\Core\Http\Response { return \FloCMS\Core\Http\Response::html('<p>fragment</p>', 202)->header('X-Fragment', 'yes'); }
    public function json(): \FloCMS\Core\Http\Response { return \FloCMS\Core\Http\Response::json(['ok' => true]); }
    public function empty(): \FloCMS\Core\Http\Response { return \FloCMS\Core\Http\Response::noContent(); }
    public function secured(): \FloCMS\Core\Http\Response { return $this->fragment(); }
    protected function helper(): string { return $this->render('helper'); }
}

abstract class AbstractResourceController extends Controller
{
}

final class NotAController
{
}

eval('namespace FloCMS\Controllers; final class PagesController extends \FloCMS\Core\Controller {
    public function index(): string { $this->data["text"] = "app:pages"; return VIEWS_PATH . "/page.html"; }
}');
eval('namespace FloCMS\Controllers; final class UsersController extends \FloCMS\Core\Controller {
    public function admin_logout(): string {
        \FloCMS\Core\Session::destroy();
        $this->data["text"] = "logged-out";
        return VIEWS_PATH . "/page.html";
    }
}');

$calls = [];
$results = [
    'resource' => ResourceController::class,
    'pass' => null,
    'missing-class' => 'NoSuchController',
    'not-a-controller' => NotAController::class,
    'abstract' => AbstractResourceController::class,
    'not-a-string' => 42,
];
foreach ((array) ($options['resolver'] ?? []) as $name) {
    App::resolveControllersUsing(static function (string $controller, string $route) use (&$calls, $results, $name): mixed {
        $calls[] = $controller . '|' . $route;
        return $results[$name];
    });
}
if ($options['clear'] ?? false) {
    App::clearControllerResolvers();
}
foreach ($options['guest'] ?? [] as [$guestController, $guestMethods]) {
    App::allowGuestAdminActions($guestController, $guestMethods);
}
if ($options['clearGuest'] ?? false) {
    App::clearGuestAdminActions();
}

$_SESSION = $options['session'] ?? (($options['admin'] ?? false) ? ['admin_access' => true] : []);
$_POST = $options['post'] ?? [];
$_SERVER['REQUEST_METHOD'] = $options['method'] ?? 'GET';
$_ENV['DEFAULT_LANG'] = 'en';
Config::set('routes', ['default' => '', 'admin' => 'admin_']);
Config::set('default_route', 'default');
Config::set('default_controller', 'pages');
Config::set('default_action', 'index');
Config::set('languages', ['en']);
Config::set('default_language', 'en');
Config::set('view.cache', false);

// Router::redirect() exits, so the result is written on shutdown
$status = 200;
$finished = false;
ob_start();
register_shutdown_function(static function () use (&$status, &$finished, &$calls): void {
    $body = (string) ob_get_clean();
    echo json_encode(['status' => $status, 'body' => $body, 'redirected' => !$finished, 'calls' => $calls, 'session' => $_SESSION]);
});
try {
    App::run($uri);
    $status = http_response_code() ?: 200;
} catch (HttpException $e) {
    $status = $e->status;
}
$finished = true;
PHP;
    }
}
