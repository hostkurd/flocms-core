<?php

namespace FloCMS\Core;

use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;

use PDO;
use PDOException;
use Throwable;

class App
{
    protected static ?Router $router = null;
    public static ?Database $db = null;
    public static ?Logger $logger = null;

    /** @var list<callable(string, string): ?string> */
    protected static array $controllerResolvers = [];

    /**
     * Supply page controllers that are not in the application's controllers/
     * folder, for example from a package.
     *
     * When no FloCMS\Controllers\<Name>Controller class exists for a URL,
     * each resolver is called in registration order with the controller
     * segment (e.g. 'properties') and the route (e.g. 'admin' or 'default').
     * The first one that returns a class name is used; return null to pass.
     * Application controllers always win, and the usual checks still apply:
     * the class must extend Controller, only its declared public actions are
     * callable, and $actionPermissions is enforced.
     *
     * @param callable(string $controller, string $route): ?string $resolver
     */
    public static function resolveControllersUsing(callable $resolver): void
    {
        self::$controllerResolvers[] = $resolver;
    }

    /** Remove every registered controller resolver. */
    public static function clearControllerResolvers(): void
    {
        self::$controllerResolvers = [];
    }

    public static function getRouter(): Router
    {
        if (!self::$router instanceof Router) {
            throw new \RuntimeException('Router not initialized. Call App::run() first.');
        }

        return self::$router;
    }

    public static function hasRouter(): bool
    {
        return self::$router instanceof Router;
    }

    public static function logger(): Logger
    {
        if (!self::$logger instanceof Logger) {
            self::$logger = new Logger(ROOT . '/storage/logs/app.log');
        }

        return self::$logger;
    }

    /** Connection failure of the current request, so the timeout is paid once. */
    private static ?DatabaseConnectionException $dbError = null;

    /**
     * The shared database connection, or null when DB_NAME / DB_USERNAME are empty.
     *
     * @throws DatabaseConnectionException when the server cannot be reached or refuses the login.
     */
    public static function db(): ?Database
    {
        if (self::$db instanceof Database) {
            return self::$db;
        }

        if (!self::hasDbConfig()) {
            return null;
        }

        if (self::$dbError !== null) {
            throw self::$dbError;
        }

        try {
            $pdo = new PDO(
                'mysql:host=' . Config::get('db.host', 'localhost') .
                ';port=' . (int) Config::get('db.port', 3306) .
                ';dbname=' . Config::get('db.name') .
                ';charset=' . Config::get('db.charset', 'utf8mb4'),
                Config::get('db.user'),
                Config::get('db.pass'),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 2,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            self::$db = new Database($pdo);
            return self::$db;
        } catch (PDOException $e) {
            throw self::$dbError = DatabaseConnectionException::fromPdo($e);
        }
    }

    /**
     * Forget the current connection and any cached connection failure.
     */
    public static function resetDb(): void
    {
        self::$db = null;
        self::$dbError = null;
    }

    public static function hasDbConfig(): bool
    {
        return (bool) (Config::get('db.name') && Config::get('db.user'));
    }

    /**
     * Database status for setup screens. Never throws.
     *
     * 'detail' holds the driver message (host and user names): show it in debug mode only.
     *
     * @return array{configured: bool, connected: bool, reason: ?string, code: ?int, message: ?string, detail: ?string}
     */
    public static function dbStatus(): array
    {
        $status = [
            'configured' => self::hasDbConfig(),
            'connected' => false,
            'reason' => null,
            'code' => null,
            'message' => null,
            'detail' => null,
        ];

        if (!$status['configured']) {
            $status['reason'] = 'not_configured';
            $status['message'] = 'Database is not configured.';
            return $status;
        }

        try {
            $status['connected'] = self::db() instanceof Database;
        } catch (DatabaseConnectionException $e) {
            $status['reason'] = $e->reason();
            $status['code'] = $e->driverCode();
            $status['message'] = $e->friendlyMessage();
            $status['detail'] = $e->detail();
        }

        return $status;
    }

    public static function run(string $uri): void
    {
        // Role definitions are read once per request (also in long-running workers).
        Auth::forgetLoadedRoles();

        try {
            self::$router = new Router($uri);
            $request = Request::fromGlobals();

            // Global configuration
            require ROOT . '/public/conf_global.php';

            // Load language
            Lang::load(self::$router->getLanguage());

            // CSRF protection for state-changing requests
            $isApiRoute = in_array(self::$router->getRoute(), ['api', 'v1'], true);

            if ($request->isStateChanging() && !$isApiRoute) {
                $token = $request->input('_token') ?? $request->header('X-CSRF-TOKEN');

                if (!is_string($token) || !Csrf::validate($token)) {
                    throw new HttpException(419);
                }
            }

            $layout = self::$router->getRoute();

            // Reload role/status so suspended or demoted users lose access immediately
            if ($layout === 'admin' && !Auth::refresh()) {
                Session::setFlash(Lang::get('auth.session_ended', 'Your session has ended. Please log in again.'), 'warning');
            }

            $hasAdminAccess = (bool) Session::get('admin_access');

            $controllerName = (string) self::$router->getController();
            $actionName = (string) self::$router->getAction();
            $methodPrefix = (string) self::$router->getMethodPrefix();

            // Controller hardening
            if (!self::isValidControllerName($controllerName)) {
                throw new HttpException(404);
            }

            if (!self::isValidActionName($actionName)) {
                throw new HttpException(404);
            }

            $controllerMethod = strtolower($methodPrefix . $actionName);

            // Admin auth gate
            // An authenticated user who lost admin access must still be able to
            // end their session. Keep this exception specific to users/logout;
            // other controllers and actions retain the normal admin gate.
            $canLogout = $controllerName === 'users'
                && $controllerMethod === 'admin_logout'
                && (bool) Session::get('isloggedin');

            if ($layout === 'admin' && !$hasAdminAccess && $controllerMethod !== 'admin_login' && !$canLogout) {
                Router::redirect(SITE_URI . DS . ACTIVE_LANG . DS . 'admin/users/login/');
                return;
            }

            if ($layout === 'admin' && $hasAdminAccess && $controllerMethod === 'admin_login') {
                Router::redirect(SITE_URI . DS . ACTIVE_LANG . DS . 'admin/');
                return;
            }

            // Maintenance mode
            if (
                !$hasAdminAccess &&
                $layout !== 'admin' &&
                Config::getSetting('offline_mode') === '1'
            ) {
                $offlinePath = Template::getOfflinePath();
                echo (new View(null, $offlinePath))->render();
                return;
            }

            // After the admin gate, so resolvers never run for visitors who must log in first
            $controllerClass = self::resolveControllerClass($controllerName, (string) $layout);
            if ($controllerClass === null) {
                throw new HttpException(404);
            }

            $controller = new $controllerClass();

            if (!$controller instanceof Controller) {
                throw new \RuntimeException(
                    'Invalid controller class: ' . $controllerClass . ' must extend FloCMS\\Core\\Controller.'
                );
            }

            $controller->setRequest($request);

            if (!self::isActionMethod($controllerClass, $controllerMethod)) {
                throw new HttpException(404);
            }

            self::assertActionAllowed($controller, $controllerMethod);

            $viewPath = $controller->$controllerMethod();
            // Explicit responses bypass template/layout rendering, after the
            // same CSRF, admin gate and action permission checks as page views.
            if ($viewPath instanceof Response) {
                ($request->isMethod('HEAD') ? $viewPath->withoutBody() : $viewPath)->send();
                return;
            }
            $content = (new View($controller->getData(), $viewPath))->render();

            $layoutPath = Template::getLayoutPath($layout);
            echo (new View(compact('content'), $layoutPath))->render();
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw $e;
        }
    }

    /**
     * True when $controller may name a controller: letters, digits and '_',
     * with single hyphens between words. "blog-post" and "blog_post" both
     * map to BlogPostController (see buildControllerClassName()).
     */
    protected static function isValidControllerName(string $controller): bool
    {
        return preg_match('/^[a-zA-Z0-9_]+(?:-[a-zA-Z0-9_]+)*$/', $controller) === 1;
    }

    /**
     * True when $action may name a controller method: letters, digits and '_',
     * not starting with '__' (magic methods such as __construct).
     */
    protected static function isValidActionName(string $action): bool
    {
        return $action !== ''
            && preg_match('/^[a-zA-Z0-9_]+$/', $action) === 1
            && !str_starts_with($action, '__');
    }

    /**
     * True when $method can be called from a URL: a public, non-static method of
     * $controllerClass that is not the constructor and not part of the base
     * Controller API (getData(), setRequest(), permissionFor(), ...).
     */
    protected static function isActionMethod(string $controllerClass, string $method): bool
    {
        if (!method_exists($controllerClass, $method)) {
            return false;
        }

        $reflection = new \ReflectionMethod($controllerClass, $method);

        return $reflection->isPublic()
            && !$reflection->isStatic()
            && !$reflection->isConstructor()
            && $reflection->getDeclaringClass()->getName() !== Controller::class;
    }

    /**
     * @throws HttpException 403 when the action requires a permission the user lacks.
     */
    protected static function assertActionAllowed(Controller $controller, string $method): void
    {
        $permission = $controller->permissionFor($method);

        if ($permission !== null) {
            Auth::authorize($permission);
        }
    }

    /**
     * The controller class for a URL segment: the application's own controller
     * when it exists, otherwise the first class a resolver returns. Null when
     * there is none, or when a resolver returns something that is not an
     * instantiable Controller subclass.
     */
    protected static function resolveControllerClass(string $controllerName, string $route): ?string
    {
        $class = self::buildControllerClassName($controllerName);
        if (class_exists($class)) {
            return $class;
        }

        foreach (self::$controllerResolvers as $resolver) {
            $resolved = $resolver($controllerName, $route);
            if ($resolved === null) {
                continue;
            }

            if (
                !is_string($resolved)
                || !class_exists($resolved)
                || !is_subclass_of($resolved, Controller::class)
                || !(new \ReflectionClass($resolved))->isInstantiable()
            ) {
                return null;
            }

            return $resolved;
        }

        return null;
    }

    protected static function buildControllerClassName(string $controllerName): string
    {
        $controllerName = str_replace(['-', '.'], '_', $controllerName);
        $controllerName = str_replace(' ', '', ucwords(str_replace('_', ' ', $controllerName)));

        return 'FloCMS\\Controllers\\' . $controllerName . 'Controller';
    }
}
