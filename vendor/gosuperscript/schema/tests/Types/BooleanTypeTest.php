<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Schema\Exceptions\TransformValueException;
use Superscript\Schema\Types\BooleanType;
use Superscript\Schema\Types\StringType;

use function Superscript\Monads\Option\None;

#[CoversClass(BooleanType::class)]
#[CoversClass(TransformValueException::class)]
final class BooleanTypeTest extends TestCase
{
    #[DataProvider('coerceProvider')]
    #[Test]
    public function it_can_coerce_value(mixed $value, ?bool $expected): void
    {
        $type = new BooleanType;
        $result = $type->coerce($value);

        $this->assertTrue($result->isOk());
        $this->assertEquals($expected, $result->unwrapOr(None())->unwrapOr(null));
    }

    public static function coerceProvider(): array
    {
        return [
            [true, true],
            [false, false],
            ['true', true],
            ['false', false],
            ['yes', true],
            ['no', false],
            ['on', true],
            ['off', false],
            ['1', true],
            ['0', false],
            [1, true],
            [0, false],
            [null, false],
        ];
    }

    #[DataProvider('assertProvider')]
    #[Test]
    public function it_can_assert_value(bool $value, bool $expected): void
    {
        $type = new BooleanType;
        $result = $type->assert($value);

        $this->assertTrue($result->isOk());
        $this->assertEquals($expected, $result->unwrapOr(None())->unwrapOr(null));
    }

    public static function assertProvider(): array
    {
        return [
            [true, true],
            [false, false],
        ];
    }

    #[Test]
    public function it_returns_err_if_it_fails_to_coerce()
    {
        $type = new BooleanType;
        $result = $type->coerce($value = 'foobar');
        $this->assertEquals($result->unwrapErr(), new TransformValueException(type: 'boolean', value: $value));
        $this->assertEquals($result->unwrapErr()->getMessage(), 'Unable to transform into [boolean] from [\'foobar\']');
    }

    #[Test]
    public function it_returns_err_if_it_fails_to_assert()
    {
        $type = new BooleanType;
        $result = $type->assert($value = 'foobar');
        $this->assertEquals($result->unwrapErr(), new TransformValueException(type: 'boolean', value: $value));
        $this->assertEquals($result->unwrapErr()->getMessage(), 'Unable to transform into [boolean] from [\'foobar\']');
    }

    #[DataProvider('compareProvider')]
    #[Test]
    public function test_can_compare_two_values(bool $a, bool $b, bool $expected): void
    {
        $type = new BooleanType;
        $this->assertSame($expected, $type->compare($a, $b));
    }

    public static function compareProvider(): array
    {
        return [
            [true, true, true],
            [true, false, false],
        ];
    }

    #[DataProvider('formatProvider')]
    #[Test]
    public function test_can_format_value(bool $value, string $expected): void
    {
        $type = new BooleanType;
        $this->assertSame($expected, $type->format($value));
    }

    public static function formatProvider(): array
    {
        return [
            [true, 'True'],
            [false, 'False'],
        ];
    }
}
