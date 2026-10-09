<?php
namespace FloCMS\Core;

use RuntimeException;

class View{
    protected $data;
    protected $path;
    protected $cache;

    public static function getDefaultViewPath(){
        $router = App::getRouter();
        if (!$router){
            return false;
        }
        $controller_dir = $router->getController();
        $template_name = $router->getMethodPrefix().$router->getAction().'.html';
        return VIEWS_PATH.DS.$controller_dir.DS.$template_name;
    }

    public function __construct($data=array(), $path=null){
        if(!$path){
            $path = self::getDefaultViewPath();
        }
        if (!file_exists($path)){
            throw new RuntimeException('View file does not exist: ' . $path);
        }
        $this->cache = VIEWS_PATH.DS.'cache'.DS;
        $this->path = $path;
        $this->data = $data;
    }

    public function render(): string
    {
        // make $data variables available in the template
        $data = is_array($this->data) ? $this->data : [];

        // Compiled once per template change and included, so OPcache can cache it
        $__floCompiled = TemplateEngine::compiledPath($this->path);
        $__floSource = null;

        if ($__floCompiled === null) {
            $raw = file_get_contents($this->path);
            if ($raw === false) {
                throw new RuntimeException("View not found: {$this->path}");
            }

            // compile template syntax ({{ }}, @if, etc)
            $__floSource = TemplateEngine::Decode($raw);
            unset($raw);
        }

        extract($data, EXTR_SKIP);

        $__floLevel = ob_get_level();
        ob_start();

        try {
            if ($__floCompiled !== null && is_file($__floCompiled)) {
                include $__floCompiled;
            } else {
                eval('?>' . ($__floSource ?? TemplateEngine::Decode((string) file_get_contents($this->path))));
            }
        } catch (\Throwable $e) {
            while (ob_get_level() > $__floLevel) {
                ob_end_clean();
            }

            throw $e;
        }

        return (string) ob_get_clean();
    }
}
