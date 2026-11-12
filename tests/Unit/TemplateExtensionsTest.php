<?php
declare(strict_types=1);

namespace FloCMS\Core\Tests\Unit;

use FloCMS\Core\Config;
use FloCMS\Core\TemplateEngine;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Directives added with TemplateEngine::extend() (2.10).
 */
final class TemplateExtensionsTest extends TestCase
{
    private string $cache = '';

    protected function setUp(): void
    {
        Config::$settings = [];
        TemplateEngine::clearExtensions();
    }

    protected function tearDown(): void
    {
        TemplateEngine::clearExtensions();
        Config::$settings = [];
        if ($this->cache !== '' && is_dir($this->cache)) {
            foreach (glob($this->cache . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->cache);
        }
    }

    private function render(string $template, array $data = []): string
    {
        $code = TemplateEngine::Decode($template);

        return (static function (string $__code, array $__data): string {
            extract($__data);
            ob_start();
            eval('?>' . $__code);
            return (string) ob_get_clean();
        })($code, $data);
    }

    public function testADirectiveWithAnExpression(): void
    {
        TemplateEngine::extend('upper', static fn (?string $e): string => '<?= strtoupper(' . $e . ') ?>');

        self::assertSame('Hi ADA!', $this->render('Hi @upper($name)!', ['name' => 'ada']));
    }

    public function testTheExpressionMayHoldCallsArraysAndQuotedParentheses(): void
    {
        TemplateEngine::extend('count', static fn (?string $e): string => '<?= count(' . $e . ') ?>');

        self::assertSame('3', $this->render('@count(array_filter([1, 2, 3, ")"], "is_int"))'));
    }

    public function testADirectiveWithoutParenthesesGetsNull(): void
    {
        $seen = 'unset';
        TemplateEngine::extend('hr', static function (?string $e) use (&$seen): string {
            $seen = $e;
            return '<hr>';
        });

        self::assertSame('a <hr> b', $this->render('a @hr b'));
        self::assertNull($seen);
    }

    public function testEmptyParenthesesGiveAnEmptyString(): void
    {
        $seen = null;
        TemplateEngine::extend('hr', static function (?string $e) use (&$seen): string {
            $seen = $e;
            return '<hr>';
        });

        $this->render('@hr()');
        self::assertSame('', $seen);
    }

    public function testNamesAreMatchedAsWholeWords(): void
    {
        TemplateEngine::extend('panel', static fn (): string => '[P]');
        TemplateEngine::extend('endpanel', static fn (): string => '[/P]');

        self::assertSame(
            '[P]x [/P] @panels mail info@panel.com admin@panel info@panel-co.com',
            $this->render('@panel()x @endpanel @panels mail info@panel.com admin@panel info@panel-co.com')
        );
    }

    public function testExtensionsDoNotCollideWithBuiltInDirectives(): void
    {
        // Before 2.10 a str_replace of @endfor would cut @endform in half
        TemplateEngine::extend('form', static fn (?string $e): string => '<form data-name="' . trim((string) $e, '\'"') . '">');
        TemplateEngine::extend('endform', static fn (): string => '</form>');

        $html = $this->render('@form(\'contact\')@for($i = 0; $i < 2; $i++)<i>{{ $i }}</i>@endfor @endform');

        self::assertSame('<form data-name="contact"><i>0</i><i>1</i> </form>', $html);
        // Right after text it is text, but the built-in @endfor still can't cut it in half
        self::assertSame('x@endform', $this->render('x@endform'));
    }

    public function testTheEscapeIsSafeFromBuiltInsToo(): void
    {
        TemplateEngine::extend('endform', static fn (): string => '</form>');
        TemplateEngine::extend('phpinfo', static fn (): string => '');

        self::assertSame('Write @endform and @phpinfo here', $this->render('Write @@endform and @@phpinfo here'));
    }

    public function testNestingIsRefusedWhateverTheOrder(): void
    {
        foreach ([['upper', 'lower'], ['lower', 'upper']] as $order) {
            TemplateEngine::clearExtensions();
            foreach ($order as $name) {
                TemplateEngine::extend($name, static fn (?string $e): string => '<?= ' . $name . '(' . $e . ') ?>');
            }
            try {
                TemplateEngine::Decode('@upper(@lower($x))');
                self::fail('Expected an exception for ' . implode(', ', $order));
            } catch (UnexpectedValueException $error) {
                self::assertStringContainsString('nested', $error->getMessage());
            }
        }
    }

    public function testATypedCompilerUsedWithoutParenthesesGivesAClearError(): void
    {
        TemplateEngine::extend('upper', static fn (string $e): string => '<?= strtoupper(' . $e . ') ?>');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('?string');
        TemplateEngine::Decode('@upper');
    }

    public function testTheOutputIsNotProcessedAgain(): void
    {
        TemplateEngine::extend('literal', static fn (): string => '{{ not a variable }} @if');

        self::assertSame('{{ not a variable }} @if', $this->render('@literal'));
    }

    public function testDoubleAtPrintsTheDirectiveName(): void
    {
        TemplateEngine::extend('member', static fn (): string => '<?php if (true): ?>');

        self::assertSame('Write @member in a template.', $this->render('Write @@member in a template.'));
    }

    public function testBuiltInNamesAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TemplateEngine::extend('ENDIF', static fn (): string => '');
    }

    public function testInvalidNamesAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TemplateEngine::extend('my-directive', static fn (): string => '');
    }

    public function testACompilerMustReturnAString(): void
    {
        TemplateEngine::extend('broken', static fn (): ?string => null);

        $this->expectException(UnexpectedValueException::class);
        TemplateEngine::Decode('@broken');
    }

    public function testForgetAndList(): void
    {
        TemplateEngine::extend('a', static fn (): string => 'A');
        TemplateEngine::extend('b', static fn (): string => 'B');
        TemplateEngine::forget('a');

        self::assertSame(['b'], TemplateEngine::extensions());
        self::assertSame('@a B', $this->render('@a @b'));
    }

    public function testCompiledFilesChangeWithExtensionsAndVersions(): void
    {
        $this->cache = sys_get_temp_dir() . '/flo-ext-' . bin2hex(random_bytes(4));
        Config::$settings['view.cache_path'] = $this->cache;
        $template = $this->cache . '-tpl.html';
        file_put_contents($template, '@greet');

        try {
            $plain = TemplateEngine::compiledPath($template);
            TemplateEngine::extend('greet', static fn (): string => 'Hello');
            $first = TemplateEngine::compiledPath($template);
            TemplateEngine::extend('greet', static fn (): string => 'Hi', '2');
            $second = TemplateEngine::compiledPath($template);

            self::assertNotSame($plain, $first);
            self::assertNotSame($first, $second);
            self::assertSame('Hi', file_get_contents((string) $second));

            TemplateEngine::clearExtensions();
            self::assertSame(basename((string) $plain), basename((string) TemplateEngine::compiledPath($template)), 'without extensions the name stays as before 2.10');
            // Requests that register different directives keep each other's files
            self::assertFileExists((string) $second);
        } finally {
            @unlink($template);
        }
    }
}
