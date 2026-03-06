<?php
namespace FloCMS\Core;

class Router
{
    protected $uri;
    protected $controller;
    protected $action;
    protected $params;
    protected $method_prefix;
    protected $language;
    protected $route;

    public function getUri()
    {
        return $this->uri;
    }

    public function getController()
    {
        return $this->controller;
    }

    public function getAction()
    {
        return $this->action;
    }

    public function getParams()
    {
        return $this->params;
    }

    public function getMethodPrefix()
    {
        return $this->method_prefix;
    }

    public function getLanguage()
    {
        return $this->language;
    }

    public function getRoute()
    {
        return $this->route;
    }

    public function __construct($uri)
    {
        $routes = Config::get('routes');
        $this->route = Config::get('default_route');
        $this->method_prefix = isset($routes[$this->route]) ? $routes[$this->route] : '';
        $this->language = Env::get('DEFAULT_LANG');
        $this->controller = Config::get('default_controller');
        $this->action = Config::get('default_action');
        $this->params = [];

        // Parse request path only
        $path = parse_url((string)$uri, PHP_URL_PATH) ?? '/';
        $path = urldecode($path);

        // Detect runtime base path from the current script, not APP_URL
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $scriptDir = str_replace('\\', '/', dirname($scriptName));
        $scriptDir = rtrim($scriptDir, '/');

        // If front controller lives in /public/index.php and URL is rewritten
        // to /myapp/, then dirname(SCRIPT_NAME) may be /flocms/public.
        // In that case we usually want /flocms as the app base.
        if (substr($scriptDir, -7) === '/public') {
            $scriptDir = substr($scriptDir, 0, -7);
        }

        $basePath = trim($scriptDir, '/');

        $pathParts = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));

        if ($basePath !== '') {
            $baseParts = array_values(array_filter(explode('/', $basePath), 'strlen'));

            foreach ($baseParts as $part) {
                if (!empty($pathParts) && strtolower((string)$pathParts[0]) === strtolower((string)$part)) {
                    array_shift($pathParts);
                } else {
                    break;
                }
            }
        }

        $this->uri = implode('/', $pathParts);

        // Language
        if (!empty($pathParts) && in_array(strtolower((string)$pathParts[0]), Config::get('languages'), true)) {
            $this->language = strtolower((string)$pathParts[0]);
            array_shift($pathParts);
        }

        // Route
        if (!empty($pathParts) && in_array(strtolower((string)$pathParts[0]), array_keys($routes), true)) {
            $this->route = strtolower((string)$pathParts[0]);
            $this->method_prefix = isset($routes[$this->route]) ? $routes[$this->route] : '';
            array_shift($pathParts);
        }

        // Controller
        if (!empty($pathParts)) {
            $this->controller = strtolower((string)$pathParts[0]);
            array_shift($pathParts);
        }

        // Action
        if (!empty($pathParts)) {
            $this->action = strtolower((string)$pathParts[0]);
            array_shift($pathParts);
        }

        // Params
        $this->params = array_values($pathParts);
    }

    public static function redirect(string $location): void
    {
        header("Location: $location", true, 302);
        exit;
    }

    public function changeLang($lang)
    {
        $controller = $this->getController();
        $action = $this->getAction();
        $params = $this->getParams();

        $langP = ($lang === Env::get('DEFAULT_LANG')) ? '' : '/' . $lang;

        if ($controller === 'pages' && $action === 'index') {
            $controllerP = '';
        } else {
            $controllerP = isset($controller) ? '/' . $controller : '';
        }

        $actionP = (isset($action) && $action !== 'index') ? '/' . $action : '';
        $paramsP = !empty($params) ? '/' . implode('/', $params) : '';

        return $langP . $controllerP . $actionP . $paramsP;
    }
}