<?php
declare(strict_types=1);

namespace FloCMS\Core\Tests\Unit;

use FloCMS\Core\App;
use FloCMS\Core\Controller;
use PHPUnit\Framework\TestCase;

/**
 * Only methods the application controller declares as public, non-static
 * actions can be reached from a URL.
 */
final class ActionDispatchTest extends TestCase
{
    public function testPublicActionsAreReachable(): void
    {
        self::assertTrue(DispatchProbe::isAction(ShopProbeController::class, 'index'));
        self::assertTrue(DispatchProbe::isAction(ShopProbeController::class, 'admin_index'));
    }

    public function testActionsFromAnIntermediateBaseControllerAreReachable(): void
    {
        self::assertTrue(DispatchProbe::isAction(ShopProbeController::class, 'shared'));
    }

    public function testMissingMethodsAreNotReachable(): void
    {
        self::assertFalse(DispatchProbe::isAction(ShopProbeController::class, 'missing'));
    }

    public function testProtectedPrivateAndStaticMethodsAreNotReachable(): void
    {
        self::assertFalse(DispatchProbe::isAction(ShopProbeController::class, 'helper'));
        self::assertFalse(DispatchProbe::isAction(ShopProbeController::class, 'secret'));
        self::assertFalse(DispatchProbe::isAction(ShopProbeController::class, 'staticthing'));
    }

    public function testBaseControllerMethodsAreNotReachable(): void
    {
        foreach (['getdata', 'getmodel', 'getparams', 'setrequest', 'permissionfor', '__construct'] as $method) {
            self::assertFalse(DispatchProbe::isAction(ShopProbeController::class, $method), $method);
        }
    }

    public function testActionNames(): void
    {
        self::assertTrue(DispatchProbe::validName('index'));
        self::assertTrue(DispatchProbe::validName('admin_edit2'));
        self::assertFalse(DispatchProbe::validName(''));
        self::assertFalse(DispatchProbe::validName('__construct'));
        self::assertFalse(DispatchProbe::validName('__call'));
        self::assertFalse(DispatchProbe::validName('get-started'));
        self::assertFalse(DispatchProbe::validName('1.7'));
    }

    public function testControllerNames(): void
    {
        foreach (['pages', 'blog_post', 'blog-post', 'blog-post-archive', 'v2-api', 'Users'] as $name) {
            self::assertTrue(DispatchProbe::validController($name), $name);
        }
        foreach (['', '-blog', 'blog-', 'blog--post', 'blog.post', '1.7', 'blog post', '../pages', 'blog/post'] as $name) {
            self::assertFalse(DispatchProbe::validController($name), $name);
        }
    }

    public function testHyphenatedAndUnderscoredUrlsReachTheSameController(): void
    {
        self::assertSame('FloCMS\\Controllers\\BlogPostController', DispatchProbe::className('blog-post'));
        self::assertSame('FloCMS\\Controllers\\BlogPostController', DispatchProbe::className('blog_post'));
        self::assertSame('FloCMS\\Controllers\\PagesController', DispatchProbe::className('pages'));
    }
}

final class DispatchProbe extends App
{
    public static function isAction(string $class, string $method): bool
    {
        return self::isActionMethod($class, $method);
    }

    public static function validName(string $action): bool
    {
        return self::isValidActionName($action);
    }

    public static function validController(string $controller): bool
    {
        return self::isValidControllerName($controller);
    }

    public static function className(string $controller): string
    {
        return self::buildControllerClassName($controller);
    }
}

abstract class BaseProbeController extends Controller
{
    public function shared(): void
    {
    }
}

final class ShopProbeController extends BaseProbeController
{
    public function __construct()
    {
    }

    public function index(): void
    {
    }

    public function admin_index(): void
    {
    }

    protected function helper(): void
    {
    }

    private function secret(): void
    {
    }

    public static function staticThing(): void
    {
    }
}
