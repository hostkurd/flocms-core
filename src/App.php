<?php

namespace FloCMS\Core;

use FloCMS\Core\Http\Request;

use PDO;
use PDOException;
use Throwable;

class App
{
    protected static ?Router $router = null;
    public static ?Database $db = null;
    public static ?Logger $logger = null;

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

    public static function db(): ?Database
    {
        if (self::$db instanceof Database) {
            return self::$db;
        }

        if (!self::hasDbConfig()) {
            return null;
        }

        try {
            $pdo = new PDO(
                'mysql:host=' . Config::get('db.host', 'localhost') .
                ';dbname=' . Config::get('db.name') .
                ';charset=' . Config::get('db.charset', 'utf8mb4'),
                Config::get('db.user'),
                Config::get('db.pass'),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 2,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );

            self::$db = new Database($pdo);
            return self::$db;
        } catch (PDOException $e) {
            throw new \RuntimeException('Database connection failed. Check DB config.', 0, $e);
        }
    }

    private static function hasDbConfig(): bool
    {
        return (bool) (Config::get('db.name') && Config::get('db.user'));
    }

    public static function run(string $uri): void
    {
        try {
            self::$router = new Router($uri);
            $request = Request::fromGlobals();

            // Global configuration
            require ROOT . '/public/conf_global.php';

            // Load language
            Lang::load(self::$router->getLanguage());

            // CSRF protection for state-changing requests
            if ($request->isStateChanging()) {
                $token = $request->input('_token') ?? $request->header('X-CSRF-TOKEN');

                if (!Csrf::validate($token)) {
                    throw new HttpException(419);
                }
            }

            $layout = self::$router->getRoute();
            $hasAdminAccess = (bool) Session::get('admin_access');

            $controllerName = (string) self::$router->getController();
            $actionName = (string) self::$router->getAction();
            $methodPrefix = (string) self::$router->getMethodPrefix();

            // Controller hardening
            if ($controllerName === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $controllerName)) {
                throw new HttpException(404);
            }

            if ($actionName === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $actionName)) {
                throw new HttpException(404);
            }

            $controllerClass = self::buildControllerClassName($controllerName);
            $controllerMethod = strtolower($methodPrefix . $actionName);

            // Admin auth gate
            if ($layout === 'admin' && !$hasAdminAccess && $controllerMethod !== 'admin_login') {
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

            if (!class_exists($controllerClass)) {
                throw new HttpException(404);
            }

            $controller = new $controllerClass();

            if (!$controller instanceof Controller) {
                throw new \RuntimeException(
                    'Invalid controller class: ' . $controllerClass . ' must extend FloCMS\\Core\\Controller.'
                );
            }

            $controller->setRequest($request);

            if (!method_exists($controller, $controllerMethod)) {
                throw new HttpException(404);
            }

            $viewPath = $controller->$controllerMethod();
            $content = (new View($controller->getData(), $viewPath))->render();

            $controllerLayout = $controller->getLayout();
            $finalLayout = ($controllerLayout !== null) ? $controllerLayout : $layout;

            if ($finalLayout === '') {
                echo $content;
                return;
            }

            $layoutPath = Template::getLayoutPath($finalLayout);
            echo (new View(compact('content'), $layoutPath))->render();

        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw $e;
        }
    }

    protected static function buildControllerClassName(string $controllerName): string
    {
        $controllerName = str_replace(['-', '.'], '_', $controllerName);
        $controllerName = str_replace(' ', '', ucwords(str_replace('_', ' ', $controllerName)));

        return 'FloCMS\\Controllers\\' . $controllerName . 'Controller';
    }
}