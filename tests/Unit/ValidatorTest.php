<?php
declare(strict_types=1);

namespace FloCMS\Core\Tests\Unit;

use FloCMS\Core\MinRule;
use FloCMS\Core\ValidationException;
use FloCMS\Core\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private static function errors(array $data, array $rules): array
    {
        try {
            Validator::validate($data, $rules);
            return [];
        } catch (ValidationException $e) {
            return $e->getErrors();
        }
    }

    private static function passes(mixed $value, string $rules): bool
    {
        return self::errors(['field' => $value], ['field' => $rules]) === [];
    }

    public static function integers(): array
    {
        return [
            'int' => [5, true],
            'negative int' => [-12, true],
            'numeric string from a form' => ['5', true],
            'negative string' => ['-12', true],
            'zero string' => ['0', true],
            'float string' => ['5.5', false],
            'float' => [5.0, false],
            'text' => ['five', false],
            'empty' => ['', false],
            'bool' => [true, false],
            'null' => [null, false],
            'array' => [[1], false],
        ];
    }

    #[DataProvider('integers')]
    public function testIntegerRule(mixed $value, bool $expected): void
    {
        self::assertSame($expected, self::passes($value, 'integer'));
    }

    public static function numbers(): array
    {
        return [
            'int' => [5, true],
            'float' => [5.5, true],
            'numeric string' => ['12.50', true],
            'exponent' => ['1e3', true],
            'text' => ['12abc', false],
            'infinite' => [INF, false],
            'bool' => [false, false],
            'null' => [null, false],
        ];
    }

    #[DataProvider('numbers')]
    public function testNumericRule(mixed $value, bool $expected): void
    {
        self::assertSame($expected, self::passes($value, 'numeric'));
    }

    public function testMinAndMaxCompareNumbersWhenFieldIsNumeric(): void
    {
        // min:1000 on a price used to compare the string length
        self::assertFalse(self::passes('999', 'integer|min:1000'));
        self::assertTrue(self::passes('1000', 'integer|min:1000'));
        self::assertTrue(self::passes('250000', 'numeric|min:1000|max:500000'));
        self::assertFalse(self::passes('500000.01', 'numeric|max:500000'));
        self::assertTrue(self::passes(18, 'required|integer|min:18'));
        // rule order does not matter
        self::assertFalse(self::passes('5', 'min:10|integer'));
    }

    public function testMinAndMaxCompareLengthOtherwise(): void
    {
        // A PIN or phone number stays a string: "0123" has 4 characters
        self::assertTrue(self::passes('0123', 'min:4|max:4'));
        self::assertFalse(self::passes('012', 'min:4'));
        self::assertTrue(self::passes('5', 'max:3'));
        // Multibyte text counts characters, not bytes
        self::assertTrue(self::passes('سڵاو', 'max:4'));
        self::assertFalse(self::passes('سڵاوی', 'max:4'));
        // Arrays count items
        self::assertTrue(self::passes(['a', 'b'], 'min:2'));
        self::assertFalse(self::passes(['a', 'b', 'c'], 'max:2'));
    }

    public function testMinFailsOnMissingValueWithoutWarnings(): void
    {
        self::assertFalse(self::passes(null, 'min:1'));
        self::assertFalse(self::passes('', 'min:1'));
    }

    public function testMessagesDependOnContext(): void
    {
        $errors = self::errors(['price' => '5', 'name' => 'ab'], ['price' => 'integer|min:1000', 'name' => 'min:3']);

        self::assertSame(['price must be at least 1000.'], $errors['price']);
        self::assertSame(['name must be at least 3 characters.'], $errors['name']);
    }

    public function testRulesCanStillBeConstructedDirectly(): void
    {
        self::assertTrue((new MinRule(3))->passes('abcd'));
        self::assertTrue((new MinRule(1000, true))->passes('1500'));
    }
}
