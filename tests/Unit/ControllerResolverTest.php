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
    protected array $actionPermissions = ['delete' => 'resources.delete'];

    private function render(string $action, array $params = []): string
    {
        $this->data['text'] = implode(':', ['resource', App::getRouter()->getController(), $action, ...$params]);
        return VIEWS_PATH . '/page.html';
    }

    public function index(): string { return $this->render('index'); }
    public function edit(): string { return $this->render('edit', $this->params); }
    public function delete(): string { return $this->render('delete', $this->params); }
    public function admin_create(): string { return $this->render('admin_create'); }
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

$_SESSION = ($options['admin'] ?? false) ? ['admin_access' => true] : [];
$_SERVER['REQUEST_METHOD'] = 'GET';
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
    echo json_encode(['status' => $status, 'body' => $body, 'redirected' => !$finished, 'calls' => $calls]);
});
try {
    App::run($uri);
} catch (HttpException $e) {
    $status = $e->status;
}
$finished = true;
PHP;
    }
}
