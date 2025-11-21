<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Superscript\Schema\Types\NumberType;
use Superscript\Schema\Exceptions\TransformValueException;

use function Superscript\Monads\Option\None;

#[CoversClass(NumberType::class)]
#[CoversClass(TransformValueException::class)]
class NumberTypeTest extends TestCase
{
    #[DataProvider('coerceProvider')]
    #[Test]
    public function it_can_coerce_a_value(mixed $value, int|float|null $expected)
    {
        $type = new NumberType();
        $result = $type->coerce($value);
        $this->assertTrue($result->isOk(), "Coercing " . var_export($value, true) . " should return Ok, not Err");
        $this->assertSame($expected, $result->unwrapOr(None())->unwrapOr(null));
    }

    public static function coerceProvider(): array
    {
        return [
            [1, 1],
            [1.1, 1.1],
            ['42', 42],
            ['1.1', 1.1],
            ['45%', 0.45],
            ['', null],
            ['null', null],
        ];
    }

    #[DataProvider('assertProvider')]
    #[Test]
    public function it_can_assert_a_value(int|float $value, int|float $expected)
    {
        $type = new NumberType();
        $this->assertSame($expected, $type->assert($value)->unwrapOr(None())->unwrapOr(null));
    }

    public static function assertProvider(): array
    {
        return [
            [1, 1],
            [1.1, 1.1],
            [42, 42],
        ];
    }

    #[Test]
    public function it_returns_err_if_it_fails_to_coerce()
    {
        $type = new NumberType();
        $result = $type->coerce($value = 'foobar');
        $this->assertEquals(new TransformValueException(type: 'numeric', value: $value), $result->unwrapErr());
        $this->assertEquals('Unable to transform into [numeric] from [\'foobar\']', $result->unwrapErr()->getMessage());
    }

    #[Test]
    public function it_returns_err_if_it_fails_to_assert()
    {
        $type = new NumberType();
        $result = $type->assert($value = 'foobar');
        $this->assertEquals(new TransformValueException(type: 'numeric', value: $value), $result->unwrapErr());
        $this->assertEquals('Unable to transform into [numeric] from [\'foobar\']', $result->unwrapErr()->getMessage());
    }

    #[Test]
    public function it_handles_empty_and_null_strings_in_coerce()
    {
        $type = new NumberType();
        
        // Test that empty string returns None (null when unwrapped)
        $result = $type->coerce('');
        $this->assertTrue($result->isOk());
        $this->assertNull($result->unwrapOr(None())->unwrapOr(null));
        
        // Test that 'null' string returns None (null when unwrapped)
        $result = $type->coerce('null');
        $this->assertTrue($result->isOk());
        $this->assertNull($result->unwrapOr(None())->unwrapOr(null));
        
        // Test that these specific cases are different from error cases
        $result = $type->coerce('invalid');
        $this->assertTrue($result->isErr());
        
        // Test that actual numbers still work
        $result = $type->coerce(42);
        $this->assertTrue($result->isOk());
        $this->assertSame(42, $result->unwrapOr(None())->unwrapOr(null));
        
        // Critical test: ensure empty string doesn't throw error (would fail if is_string check was negated)
        $emptyResult = $type->coerce('');
        $this->assertTrue($emptyResult->isOk(), 'Empty string should return Ok(None), not an error');
        
        $nullStringResult = $type->coerce('null');
        $this->assertTrue($nullStringResult->isOk(), 'String "null" should return Ok(None), not an error');
    }

    #[DataProvider('compareProvider')]
    #[Test]
    public function it_can_compare_two_values(int|float $a, int|float $b, bool $expected)
    {
        $type = new NumberType();
        $this->assertSame($expected, $type->compare($a, $b));
    }

    public static function compareProvider(): array
    {
        return [
            [1, 1, true],
            [1.1, 1.1, true],
            [1, 1.1, false],
            [1, 2, false],
        ];
    }

    #[DataProvider('formatProvider')]
    #[Test]
    public function it_can_format_value(int|float $value, string $expected)
    {
        $type = new NumberType();
        $this->assertSame($expected, $type->format($value));
    }

    public static function formatProvider(): array
    {
        return [
            [1, '1'],
            [1.1, '1.1'],
            [10000, '10,000'],
        ];
    }
}
