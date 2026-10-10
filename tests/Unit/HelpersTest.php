<?php
declare(strict_types=1);

namespace FloCMS\Core\Tests\Unit;

use FloCMS\Core\Functions;
use FloCMS\Core\Lang;
use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    protected function setUp(): void
    {
        Lang::load('en');
    }

    public function testFunctionsEscapeIsStatic(): void
    {
        self::assertSame('&lt;b&gt;&quot;x&quot; &amp; &#039;y&#039;&lt;/b&gt;', Functions::e('<b>"x" & \'y\'</b>'));
        self::assertSame('', Functions::e(null));
        self::assertSame('5', Functions::e(5));
        // Calling it on an instance keeps working
        self::assertSame('&lt;', (new Functions())->e('<'));
    }

    public function testGlobalEscapeHelper(): void
    {
        self::assertTrue(function_exists('e'));
        self::assertSame('&lt;script&gt;', e('<script>'));
    }

    public function testLangGetKeepsTwoArgumentForm(): void
    {
        self::assertSame('Plain text', Lang::get('plain'));
        self::assertSame('Plain text', Lang::get('PLAIN', 'fallback'));
        self::assertSame('fallback', Lang::get('missing', 'fallback'));
    }

    public function testLangGetReplacesPlaceholders(): void
    {
        self::assertSame(
            'Hello, Sara! You have 3 new messages.',
            Lang::get('greeting', '', ['name' => 'Sara', 'count' => 3])
        );
        self::assertSame(
            'sara is sara_k; Sara shouts SARA',
            Lang::get('user.line', '', ['user' => 'sara', 'username' => 'sara_k'])
        );
        // Placeholders in the default are replaced too
        self::assertSame('Hi Sara', Lang::get('missing', 'Hi :name', ['name' => 'Sara']));
    }

    public function testTranslationHelper(): void
    {
        self::assertSame('Hello, Sara! You have 1 new messages.', __('greeting', ['name' => 'Sara', 'count' => 1]));
        self::assertSame('Plain text', __('plain'));
        self::assertSame('missing.key', __('missing.key'));
        self::assertSame('Default :x', __('missing.key', [], 'Default :x'));
        self::assertSame('Default 1', __('missing.key', ['x' => 1], 'Default :x'));
    }
}
