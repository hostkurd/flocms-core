<?php
namespace FloCMS\Core;

use Exception;

/**
   * TemplateEngine
   * 
   * 
   * @package    FloCMS
   * @subpackage Library
   * @author     HostKurd <info@flocms.com>
   */
class TemplateEngine{

    public function __construct(){
       
    }
    
    /**
     * Bump when Decode() output changes, so compiled files are rebuilt.
     */
    public const COMPILER_VERSION = '2.2.0';

    /**
     * Compile a template to a PHP file in the view cache and return its path.
     *
     * Compiled files are named after the template path plus a hash of its
     * mtime, size and COMPILER_VERSION, so an edited template gets a new file
     * (safe with opcache.validate_timestamps=0) and OPcache can cache them.
     * Older compiled versions of the same template are removed.
     *
     * Returns null when caching is disabled (Config 'view.cache' => false) or
     * the cache directory is not writable; callers then fall back to eval().
     */
    public static function compiledPath(string $path): ?string
    {
        if (Config::get('view.cache', true) === false) {
            return null;
        }

        $directory = self::cacheDirectory();
        $mtime = @filemtime($path);
        $size = @filesize($path);

        if ($directory === null || $mtime === false || $size === false) {
            return null;
        }

        $source = realpath($path) ?: $path;
        $name = preg_replace('/[^A-Za-z0-9_-]/', '_', pathinfo($source, PATHINFO_FILENAME)) ?: 'view';
        $prefix = $name . '_' . substr(sha1($source), 0, 16) . '_';
        $file = $directory . DIRECTORY_SEPARATOR . $prefix
            . substr(sha1($mtime . '|' . $size . '|' . self::COMPILER_VERSION), 0, 12) . '.php';

        if (is_file($file)) {
            return $file;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        // Write to a temporary file and rename it, so no request includes a half-written file
        $temp = @tempnam($directory, 'flo');
        if ($temp === false) {
            return null;
        }

        if (@file_put_contents($temp, self::Decode($raw)) === false || !@rename($temp, $file)) {
            @unlink($temp);
            return null;
        }

        @chmod($file, 0644);

        foreach (glob($directory . DIRECTORY_SEPARATOR . $prefix . '*.php') ?: [] as $old) {
            if ($old !== $file) {
                @unlink($old);
            }
        }

        return $file;
    }

    /**
     * Config 'view.cache_path', or VIEWS_PATH/cache. Null when not writable.
     */
    public static function cacheDirectory(): ?string
    {
        $directory = Config::get('view.cache_path');

        if (!is_string($directory) || $directory === '') {
            if (!defined('VIEWS_PATH')) {
                return null;
            }

            $directory = VIEWS_PATH . DIRECTORY_SEPARATOR . 'cache';
        }

        $directory = rtrim($directory, '/\\');

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return null;
        }

        return is_writable($directory) ? $directory : null;
    }

    /**
     * Render a template file with $data as variables. Uses the compiled file
     * when available, otherwise eval() as before 2.2.
     */
    public static function renderFile(string $path, array $data = []): string
    {
        return (new View($data, $path))->render();
    }

    /**
     * Create View cache file
     *
     * @deprecated 2.2 Use compiledPath(); View and render_partial() compile automatically.
     * @param  mixed $path
     * @return string
     */
    public static function CreateView($path)
    {
        if (!file_exists($path)) {
            throw new Exception("View file '$path' not found.");
        }

        $file = self::compiledPath($path);

        if ($file === null) {
            throw new Exception('View cache directory is not writable.');
        }

        return $file;
    }

    /**
     * Decode
     *
     * @param  mixed $data
     * @return string
     */
    public static function Decode($data){

        // Raw PHP block
        $data = str_replace('@php', '<?php', $data);
        $data = str_replace('@endphp', '?>', $data);

        // Escaped HTML (safe)
        $data = preg_replace('/{{\s*(.+?)\s*}}/', '<?=htmlspecialchars($1, ENT_QUOTES, "UTF-8"); ?>', $data);

        // // Raw HTML (unescaped)
        $data = preg_replace('/{!!\s*(.+?)\s*!!}/', '<?=$1; ?>', $data);

        // Conditionals
        $data = preg_replace('/@if\(\s*(.+?)\s*\)/', '<?php if($1): ?>', $data);
        $data = preg_replace('/@elseif\(\s*(.+?)\s*\)/', '<?php elseif($1): ?>', $data);
        $data = preg_replace('/@else\b/', '<?php else: ?>', $data);
        $data = str_replace('@endif', '<?php endif; ?>', $data);

        // Loops
        $data = preg_replace('/@foreach\(\s*(.+?)\s*\)/', '<?php foreach($1): ?>', $data);
        $data = str_replace('@endforeach', '<?php endforeach; ?>', $data);

        $data = preg_replace('/@for\(\s*(.+?)\s*\)/', '<?php for($1): ?>', $data);
        $data = str_replace('@endfor', '<?php endfor; ?>', $data);

        $data = preg_replace('/@while\(\s*(.+?)\s*\)/', '<?php while($1): ?>', $data);
        $data = str_replace('@endwhile', '<?php endwhile; ?>', $data);

        // Forelse / Empty loop (like Laravel Blade)
        $data = preg_replace('/@forelse\(\s*(.+?)\s*\)/', '<?php if(!empty($1)): foreach($1 as $key => $value): ?>', $data);
        $data = preg_replace('/@empty\b/', '<?php endforeach; else: ?>', $data);
        $data = str_replace('@endforelse', '<?php endif; ?>', $data);

        // Config and Lang
        $data = preg_replace('/@config\(\s*(.+?)\s*\)/', '<?=Config::get($1); ?>', $data);
        $data = preg_replace('/@lang\(\s*(.+?)\s*\)/', '<?=__($1); ?>', $data);

        // Environment Variables
        $data = preg_replace('/@env\(\s*(.+?)\s*\)/', '<?=Env::get($1);?>', $data);

        return $data;
    }
}