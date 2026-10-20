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

    /** @var list<callable(string, Request): ?Response> */
    protected static array $fallbacks = [];

    /**
     * Answer public URLs that no controller answers, for example pages a
     * package keeps in the database (/about, /services/web-design).
     *
     * Asked only on the default route (never for admin, login or api URLs),
     * after CSRF and the maintenance page, when the URL would otherwise be a
     * 404: no controller of that name (the application's or a resolver's),
     * no such action, or a segment that cannot name a controller or action
     * (such as "web-design" in second place). Each fallback gets the path
     * after the language ("services/web-design", '' for the home page) and
     * the request, and returns a Response, or null to pass to the next one.
     * When none answers, the request is a 404 as before.
     *
     * @param callable(string $path, Request $request): ?Response $fallback
     */
    public static function fallbackUsing(callable $fallback): void
    {
        self::$fallbacks[] = $fallback;
    }

    /** Remove every registered fallback. */
    public static function clearFallbacks(): void
    {
        self::$fallbacks = [];
    }

    /**
     * The first fallback's Response for this request, or a 404.
     *
     * @throws HttpException 404 when no fallback answers
     */
    protected static function fallback(Request $request): Response
    {
        $path = (string) self::$router->getPath();
        foreach (self::$fallbacks as $fallback) {
            $response = $fallback($path, $request);
            if ($response instanceof Response) {
                return $response;
            }
            if ($response !== null) {
                throw new \RuntimeException('A fallback must return a ' . Response::class . ' or null.');
            }
        }
        throw new HttpException(404);
    }

    /** @var array<string, array<string, true|string>> controller => method => true, or the only class allowed */
    protected static array $guestAdminActions = [];

    /**
     * Let visitors who are not signed in reach these admin actions, the way
     * they reach users/admin_login: for example a "forgot password" page.
     *
     * Exact controller segment and method names only (e.g. 'password' and
     * ['admin_forgot', 'admin_reset']); no wildcards. Everything else about
     * the request is unchanged: CSRF on POST, $actionPermissions, controller
     * resolvers and the action checks still apply, and every other admin
     * action still redirects to the login page.
     *
     * Pass $class (the controller class that serves them, e.g. a package's
     * controller) so guests reach only that class: when another class answers
     * the URL, for example the site's own controller of the same name, the
     * login check applies as usual.
     *
     * @param list<string> $methods
     * @param class-string|null $class
     */
    public static function allowGuestAdminActions(string $controller, array $methods, ?string $class = null): void
    {
        $controller = strtolower(trim($controller));

        if (!self::isValidControllerName($controller)) {
            throw new \InvalidArgumentException('Invalid controller name: ' . $controller);
        }

        foreach ($methods as $method) {
            $method = is_string($method) ? strtolower(trim($method)) : '';

            if (preg_match('/^[a-z][a-z0-9_]*$/', $method) !== 1) {
                throw new \InvalidArgumentException('Invalid action method name for ' . $controller . '.');
            }

            self::$guestAdminActions[$controller][$method] = $class !== null ? ltrim($class, '\\') : true;
        }
    }

    /** Remove every action registered with allowGuestAdminActions(). */
    public static function clearGuestAdminActions(): void
    {
        self::$guestAdminActions = [];
    }

    /** Whether allowGuestAdminActions() opened this controller method to guests. */
    public static function isGuestAdminAction(string $controller, string $method, ?string $class = null): bool
    {
        $allowed = self::$guestAdminActions[strtolower($controller)][strtolower($method)] ?? null;
        if ($allowed === null) {
            return false;
        }

        return $allowed === true || $class === null || strcasecmp($allowed, ltrim($class, '\\')) === 0;
    }

    /** The class a guest admin action is bound to, or null (any class, or not a guest action). */
    protected static function guestAdminActionClass(string $controller, string $method): ?string
    {
        $allowed = self::$guestAdminActions[strtolower($controller)][strtolower($method)] ?? null;

        return is_string($allowed) ? $allowed : null;
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

            // Controller hardening. On the default route a name that can't be a
            // controller or action may still be a fallback's page (fallbackUsing()).
            $isDefaultRoute = $layout === (string) Config::get('default_route', 'default') && $methodPrefix === '';
            $validNames = self::isValidControllerName($controllerName) && self::isValidActionName($actionName);
            if (!$validNames && (!$isDefaultRoute || self::$fallbacks === [])) {
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

            // Actions a package or the site opened with allowGuestAdminActions()
            $isGuestAction = self::isGuestAdminAction($controllerName, $controllerMethod);
            $guestOnly = $layout === 'admin' && !$hasAdminAccess && $isGuestAction && $controllerMethod !== 'admin_login' && !$canLogout;

            if ($layout === 'admin' && !$hasAdminAccess && $controllerMethod !== 'admin_login' && !$canLogout && !$isGuestAction) {
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

            // Nothing can answer this URL but a fallback.
            if (!$validNames) {
                self::send(self::fallback($request), $request);
                return;
            }

            // After the admin gate, so resolvers never run for visitors who must log in first
            $controllerClass = self::resolveControllerClass($controllerName, (string) $layout);
            if ($controllerClass === null) {
                if ($isDefaultRoute && self::$fallbacks !== []) {
                    self::send(self::fallback($request), $request);
                    return;
                }
                throw new HttpException(404);
            }

            // A guest action bound to a class opens only that class (not, say, the site's own controller of that name)
            $boundClass = $guestOnly ? self::guestAdminActionClass($controllerName, $controllerMethod) : null;
            if ($boundClass !== null && strcasecmp($boundClass, ltrim($controllerClass, '\\')) !== 0) {
                Router::redirect(SITE_URI . DS . ACTIVE_LANG . DS . 'admin/users/login/');
                return;
            }

            $controller = new $controllerClass();

            if (!$controller instanceof Controller) {
                throw new \RuntimeException(
                    'Invalid controller class: ' . $controllerClass . ' must extend FloCMS\\Core\\Controller.'
                );
            }

            $controller->setRequest($request);

            if (!self::isActionMethod($controllerClass, $controllerMethod)) {
                if ($isDefaultRoute && self::$fallbacks !== []) {
                    self::send(self::fallback($request), $request);
                    return;
                }
                throw new HttpException(404);
            }

            self::assertActionAllowed($controller, $controllerMethod);

            $viewPath = $controller->$controllerMethod();
            // Explicit responses bypass template/layout rendering, after the
            // same CSRF, admin gate and action permission checks as page views.
            if ($viewPath instanceof Response) {
                self::send($viewPath, $request);
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

    private static function send(Response $response, Request $request): void
    {
        ($request->isMethod('HEAD') ? $response->withoutBody() : $response)->send();
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
