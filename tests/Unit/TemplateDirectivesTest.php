<?php
declare(strict_types=1);

namespace FloCMS\Core\Tests\Unit;

use FloCMS\Core\Config;
use FloCMS\Core\TemplateEngine;
use PHPUnit\Framework\TestCase;

/**
 * Directive expressions end at the parenthesis that closes the opening one,
 * so they can contain function calls, nested arrays and quoted parentheses.
 */
final class TemplateDirectivesTest extends TestCase
{
    protected function setUp(): void
    {
        Config::$settings = [];

        // @config() compiles to the short alias that FloCMS sites define in includes/compat.php
        if (!class_exists('Config', false)) {
            class_alias(Config::class, 'Config');
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

    public function testIfWithAFunctionCall(): void
    {
        $template = '@if(count($posts) > 0)some@else none@endif';

        self::assertSame('some', $this->render($template, ['posts' => [1]]));
        self::assertSame(' none', $this->render($template, ['posts' => []]));
    }

    public function testElseifWithNestedCallsAndArrays(): void
    {
        $template = '@if(false)a@elseif(in_array($role, array_map("strval", [1, 2])))b@endif';

        self::assertSame('b', $this->render($template, ['role' => '2']));
    }

    public function testParenthesesInsideQuotedStrings(): void
    {
        self::assertSame('yes', $this->render('@if($x === ")")yes@endif', ['x' => ')']));
        self::assertSame('yes', $this->render("@if(\$x === '(')yes@endif", ['x' => '(']));
    }

    public function testForeachForAndWhileWithCalls(): void
    {
        self::assertSame('12', $this->render('@foreach(array_slice($n, 0, 2) as $i){{ $i }}@endforeach', ['n' => [1, 2, 3]]));
        self::assertSame('012', $this->render('@for($i = 0; $i < count($n); $i++){{ $i }}@endfor', ['n' => [1, 2, 3]]));
        self::assertSame('ab', $this->render('@while(($c = array_shift($q)) !== null){{ $c }}@endwhile', ['q' => ['a', 'b']]));
    }

    public function testForelseWithACall(): void
    {
        $template = '@forelse(array_filter($items)){{ $value }}@empty none@endforelse';

        self::assertSame('ab', $this->render($template, ['items' => ['a', '', 'b']]));
        self::assertSame(' none', $this->render($template, ['items' => ['', '']]));
    }

    public function testLangAndConfigWithNestedArguments(): void
    {
        Config::set('roles', 'admins');

        self::assertSame('admins', $this->render('@config(strtolower("ROLES"))'));
        self::assertSame('missing.key', $this->render('@lang(strtolower("MISSING.KEY"))'));
    }

    public function testSimpleDirectivesAreUnchanged(): void
    {
        self::assertSame('<?php if($a): ?>x<?php endif; ?>', TemplateEngine::Decode('@if( $a )x@endif'));
        self::assertSame('<?php foreach($posts as $post): ?>p<?php endforeach; ?>', TemplateEngine::Decode('@foreach($posts as $post)p@endforeach'));
    }

    public function testUnbalancedDirectivesAreLeftAlone(): void
    {
        self::assertSame('@if($broken', TemplateEngine::Decode('@if($broken'));
    }
}
