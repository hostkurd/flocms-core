<?php
namespace FloCMS\Core;

use Exception;
use InvalidArgumentException;
use UnexpectedValueException;

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
    public const COMPILER_VERSION = '2.2.2-balanced';

    /** Directives that come with the engine and can't be replaced by extend(). */
    public const BUILT_IN = ['csrf', 'php', 'endphp', 'if', 'elseif', 'else', 'endif', 'foreach', 'endforeach',
        'for', 'endfor', 'while', 'endwhile', 'forelse', 'empty', 'endforelse', 'config', 'lang', 'env'];

    /** @var array<string, array{compiler: callable, version: string}> */
    private static array $extensions = [];

    /**
     * Add a directive (2.10). $compiler receives the text between the
     * parentheses (null when the directive is used without them) and returns
     * the PHP that replaces it:
     *
     *     TemplateEngine::extend('upper', fn (?string $e): string => '<?= strtoupper(' . $e . ') ?>');
     *     TemplateEngine::extend('endpanel', fn (): string => '</div>');
     *
     * Directives are matched as whole words, as in Blade: the @ must not follow
     * a letter, digit, ".", "-" or "_" (so admin@panel stays text), and @panel
     * does not match @panels. They are compiled before the built-in ones, so
     * @endform never turns into "@endfor" + "m", and their output is not
     * processed again. Write @@name to print a literal "@name". They can't be
     * nested (@a(@b($x)) throws), and the parentheses must follow the name
     * directly. Register them at boot, before any template renders: the names
     * and versions are part of the compiled file's name, so bump $version when
     * the PHP a directive produces changes. A name already taken by another
     * extension is replaced.
     *
     * @param callable(?string): string $compiler
     */
    public static function extend(string $name, callable $compiler, string $version = '1'): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException('A directive name starts with a letter and has only letters, digits and _: "' . $name . '".');
        }
        if (in_array(strtolower($name), self::BUILT_IN, true)) {
            throw new InvalidArgumentException('@' . $name . ' is a built-in directive and can\'t be replaced.');
        }
        self::$extensions[$name] = ['compiler' => $compiler, 'version' => $version];
    }

    /** Remove a directive added with extend(). */
    public static function forget(string $name): void
    {
        unset(self::$extensions[$name]);
    }

    /** Remove every directive added with extend() (tests, long-running workers). */
    public static function clearExtensions(): void
    {
        self::$extensions = [];
    }

    /** @return list<string> the names added with extend() */
    public static function extensions(): array
    {
        return array_keys(self::$extensions);
    }

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
        $base = $prefix . substr(sha1($mtime . '|' . $size . '|' . self::COMPILER_VERSION), 0, 12);
        $signature = self::extensionSignature();
        // Without extensions the name is the same as before 2.10
        $file = $directory . DIRECTORY_SEPARATOR . $base . ($signature === '' ? '' : '-' . $signature) . '.php';

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

        // Remove compiled files of older versions of this template. Files of the
        // same version compiled with other extensions stay: requests that
        // register different directives must not delete each other's files.
        foreach (glob($directory . DIRECTORY_SEPARATOR . $prefix . '*.php') ?: [] as $old) {
            if ($old !== $file && !str_starts_with(basename($old), basename($base))) {
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

        // Directives added with extend() come first and are kept out of the
        // passes below, which could otherwise change their output
        $kept = [];
        if (self::$extensions !== []) {
            $data = self::compileExtensions((string) $data, $kept);
        }

        // CSRF hidden field for forms
        $data = preg_replace('/@csrf\b/', '<?=\\FloCMS\\Core\\Csrf::field(); ?>', $data);

        // Raw PHP block
        $data = str_replace('@php', '<?php', $data);
        $data = str_replace('@endphp', '?>', $data);

        // Escaped HTML (safe)
        $data = preg_replace('/{{\s*(.+?)\s*}}/', '<?=htmlspecialchars($1, ENT_QUOTES, "UTF-8"); ?>', $data);

        // // Raw HTML (unescaped)
        $data = preg_replace('/{!!\s*(.+?)\s*!!}/', '<?=$1; ?>', $data);

        // Directives with an expression in parentheses. The expression may
        // contain its own parentheses and quoted strings: @if(count($posts) > 0)
        $data = self::directive($data, 'if', static fn (string $e): string => '<?php if(' . $e . '): ?>');
        $data = self::directive($data, 'elseif', static fn (string $e): string => '<?php elseif(' . $e . '): ?>');
        $data = preg_replace('/@else\b/', '<?php else: ?>', $data);
        $data = str_replace('@endif', '<?php endif; ?>', $data);

        // Loops
        $data = self::directive($data, 'foreach', static fn (string $e): string => '<?php foreach(' . $e . '): ?>');
        $data = str_replace('@endforeach', '<?php endforeach; ?>', $data);

        // @endforelse first, so the @endfor replacement below can't cut it in half
        $data = str_replace('@endforelse', '<?php endif; ?>', $data);

        $data = self::directive($data, 'for', static fn (string $e): string => '<?php for(' . $e . '): ?>');
        $data = str_replace('@endfor', '<?php endfor; ?>', $data);

        $data = self::directive($data, 'while', static fn (string $e): string => '<?php while(' . $e . '): ?>');
        $data = str_replace('@endwhile', '<?php endwhile; ?>', $data);

        // Forelse / Empty loop (like Laravel Blade)
        $data = self::directive($data, 'forelse', static fn (string $e): string => '<?php if(!empty(' . $e . ')): foreach(' . $e . ' as $key => $value): ?>');
        $data = preg_replace('/@empty\b/', '<?php endforeach; else: ?>', $data);

        // Config and Lang
        $data = self::directive($data, 'config', static fn (string $e): string => '<?=Config::get(' . $e . '); ?>');
        $data = self::directive($data, 'lang', static fn (string $e): string => '<?=__(' . $e . '); ?>');

        // Environment Variables
        $data = self::directive($data, 'env', static fn (string $e): string => '<?=Env::get(' . $e . ');?>');

        return $kept === [] ? $data : strtr($data, $kept);
    }

    /**
     * Compile the extend() directives into placeholders; $kept maps each
     * placeholder to its PHP, put back at the end of Decode().
     *
     * @param array<string, string> $kept
     */
    private static function compileExtensions(string $data, array &$kept): string
    {
        $names = array_keys(self::$extensions);
        // Longer names first, so @endcomponent is never read as @end + "component"
        usort($names, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $alternatives = implode('|', array_map(static fn (string $n): string => preg_quote($n, '/'), $names));
        $expression = '((?:[^()\'"]++|\'(?:[^\'\\\\]|\\\\.)*+\'|"(?:[^"\\\\]|\\\\.)*+"|\((?1)\))*+)';
        $token = "\x00flo-directive-" . bin2hex(random_bytes(4)) . '-';
        $keep = static function (string $php) use (&$kept, $token): string {
            $key = $token . count($kept) . "\x00";
            $kept[$key] = $php;
            return $key;
        };

        // @@name prints a literal @name; kept aside so the built-in passes can't change it
        $data = preg_replace_callback('/@@(' . $alternatives . ')(?![A-Za-z0-9_])/', static fn (array $m): string => $keep('@' . $m[1]), $data) ?? $data;

        foreach ($names as $name) {
            $compiler = self::$extensions[$name]['compiler'];
            // As in Blade, the @ must not follow a letter, digit, ".", "-" or "_", so
            // admin@panel and info@panel-co.com stay as they are
            $pattern = '/(?<![A-Za-z0-9_.@-])@' . preg_quote($name, '/') . '(?![A-Za-z0-9_])(?:\(' . $expression . '\))?/';
            $result = preg_replace_callback($pattern, static function (array $m) use ($compiler, $name, $token, $keep, $alternatives): string {
                $argument = isset($m[1]) ? trim($m[1], " \t\n\r\v") : null;
                if ($argument !== null && (str_contains($argument, $token) || preg_match('/(?<![A-Za-z0-9_.@-])@(' . $alternatives . ')(?![A-Za-z0-9_])/', $argument) === 1)) {
                    throw new UnexpectedValueException('@' . $name . '(...) contains another directive: directives added with extend() can\'t be nested.');
                }
                try {
                    $php = $compiler($argument);
                } catch (\TypeError $error) {
                    throw new UnexpectedValueException('The compiler of @' . $name . ' failed: ' . $error->getMessage() . ' (its parameter should be ?string, as the directive may be used without parentheses).', 0, $error);
                }
                if (!is_string($php)) {
                    throw new UnexpectedValueException('The compiler of @' . $name . ' must return a string.');
                }
                return $keep($php);
            }, $data);
            $data = $result ?? $data;
        }

        // Whatever still looks like one of these directives (right after a word,
        // as in an email address) is text: keep it as it is
        return preg_replace_callback('/@(' . $alternatives . ')(?![A-Za-z0-9_])/', static fn (array $m): string => $keep($m[0]), $data) ?? $data;
    }

    /** Part of a compiled file's name: changes when extensions or their versions change. */
    private static function extensionSignature(): string
    {
        if (self::$extensions === []) {
            return '';
        }
        $parts = [];
        foreach (self::$extensions as $name => $extension) {
            $parts[] = $name . '@' . $extension['version'];
        }
        sort($parts);
        return substr(sha1(implode(',', $parts)), 0, 8);
    }

    /**
     * Replace @name(expression) with $compile(expression). The expression ends
     * at the parenthesis that closes the opening one, so nested calls and
     * quoted strings work: @if(in_array($role, ['a', 'b'])). Unbalanced
     * directives are left as they are.
     */
    private static function directive(string $data, string $name, callable $compile): string
    {
        // Group 1: plain characters, a quoted string, or a nested (...) group, repeated
        $expression = '((?:[^()\'"]++|\'(?:[^\'\\\\]|\\\\.)*+\'|"(?:[^"\\\\]|\\\\.)*+"|\((?1)\))*+)';
        $pattern = '/@' . $name . '\(' . $expression . '\)/';

        $result = preg_replace_callback($pattern, static fn (array $m): string => $compile(trim($m[1])), $data);

        return $result ?? $data;
    }
}
