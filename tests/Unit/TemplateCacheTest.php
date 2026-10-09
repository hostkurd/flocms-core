<?php
declare(strict_types=1);

namespace FloCMS\Core\Tests\Unit;

use FloCMS\Core\Config;
use FloCMS\Core\TemplateEngine;
use FloCMS\Core\View;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TemplateCacheTest extends TestCase
{
    private string $dir;
    private string $cache;

    protected function setUp(): void
    {
        Config::$settings = [];
        $this->dir = sys_get_temp_dir() . '/flocms-views-' . bin2hex(random_bytes(6));
        $this->cache = $this->dir . '/cache';
        mkdir($this->dir, 0777, true);
        Config::set('view.cache_path', $this->cache);
    }

    protected function tearDown(): void
    {
        Config::$settings = [];
        foreach ([$this->cache, $this->dir] as $dir) {
            array_map('unlink', array_filter(glob($dir . '/*') ?: [], 'is_file'));
            @rmdir($dir);
        }
    }

    private function template(string $name, string $source): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $source);
        return $path;
    }

    /** @return list<string> */
    private function compiledFiles(): array
    {
        return glob($this->cache . '/*.php') ?: [];
    }

    public function testRendersFromACompiledFile(): void
    {
        $path = $this->template('page.html', '<h1>{{ $title }}</h1>@if($count > 1)<p>{{ $count }} items</p>@endif{!! $html !!}');

        $html = (new View(['title' => '<Hi>', 'count' => 2, 'html' => '<b>x</b>'], $path))->render();

        self::assertSame('<h1>&lt;Hi&gt;</h1><p>2 items</p><b>x</b>', $html);
        self::assertCount(1, $this->compiledFiles());
        self::assertSame(TemplateEngine::Decode((string) file_get_contents($path)), file_get_contents($this->compiledFiles()[0]));
        self::assertStringStartsWith('page_', basename($this->compiledFiles()[0]));
    }

    public function testCompiledFileIsReusedUntilTheTemplateChanges(): void
    {
        $path = $this->template('page.html', 'v1 {{ $x }}');
        $view = fn () => (new View(['x' => 'a'], $path))->render();

        self::assertSame('v1 a', $view());
        $first = $this->compiledFiles()[0];
        // Marker proves the second render includes the existing file instead of recompiling
        file_put_contents($first, 'from cache <?= $x ?>');
        self::assertSame('from cache a', $view());

        file_put_contents($path, 'version two {{ $x }}');
        touch($path, time() + 10);
        clearstatcache();

        self::assertSame('version two a', $view());
        self::assertCount(1, $this->compiledFiles(), 'old compiled version is removed');
        self::assertNotSame($first, $this->compiledFiles()[0]);
    }

    public function testSameNameInDifferentDirectoriesDoesNotCollide(): void
    {
        mkdir($this->dir . '/a');
        mkdir($this->dir . '/b');
        $a = $this->template('a/index.html', 'A');
        $b = $this->template('b/index.html', 'B');

        self::assertSame('A', (new View([], $a))->render());
        self::assertSame('B', (new View([], $b))->render());
        self::assertSame('A', (new View([], $a))->render());
        self::assertCount(2, $this->compiledFiles());

        array_map('unlink', [$a, $b]);
        rmdir($this->dir . '/a');
        rmdir($this->dir . '/b');
    }

    public function testFallsBackToEvalWhenTheCacheIsNotWritable(): void
    {
        // A file where the cache directory should be: mkdir fails
        file_put_contents($this->dir . '/blocked', '');
        Config::set('view.cache_path', $this->dir . '/blocked/cache');
        $path = $this->template('page.html', 'Hello {{ $name }}');

        self::assertNull(TemplateEngine::compiledPath($path));
        self::assertSame('Hello Sara', (new View(['name' => 'Sara'], $path))->render());
    }

    public function testCacheCanBeDisabled(): void
    {
        Config::set('view.cache', false);
        $path = $this->template('page.html', 'Hello {{ $name }}');

        self::assertSame('Hello Sara', (new View(['name' => 'Sara'], $path))->render());
        self::assertSame([], $this->compiledFiles());
    }

    public function testDataArrayStaysAvailableToTemplates(): void
    {
        // Layouts use $data['content']
        $path = $this->template('layout.html', '<body><?=$data[\'content\']; ?></body>');

        self::assertSame('<body>inner</body>', (new View(['content' => 'inner'], $path))->render());
    }

    public function testPartialsAreCompiledToo(): void
    {
        self::assertSame('<span>&lt;new&gt;</span>', render_partial('badge', ['label' => '<new>']));
        self::assertCount(1, $this->compiledFiles());
    }

    public function testErrorInsideATemplateDoesNotLeaveOutputBuffers(): void
    {
        $path = $this->template('broken.html', 'before <?php throw new \RuntimeException("boom"); ?>');
        $level = ob_get_level();

        try {
            (new View([], $path))->render();
            self::fail('Expected the template exception.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame($level, ob_get_level());
    }

    public function testCsrfDirective(): void
    {
        $_SESSION = [];
        $path = $this->template('form.html', '<form method="post">@csrf</form><p>user@csrfexample.com</p>');

        $html = (new View([], $path))->render();

        self::assertSame(
            '<form method="post"><input type="hidden" name="_token" value="' . \FloCMS\Core\Csrf::token() . '"></form><p>user@csrfexample.com</p>',
            $html
        );
        $_SESSION = [];
    }

    public function testCreateViewReturnsTheCompiledFile(): void
    {
        $path = $this->template('legacy.html', 'Legacy {{ $x }}');

        $file = TemplateEngine::CreateView($path);

        self::assertFileExists($file);
        $x = 'ok';
        ob_start();
        include $file;
        self::assertSame('Legacy ok', ob_get_clean());
    }
}
