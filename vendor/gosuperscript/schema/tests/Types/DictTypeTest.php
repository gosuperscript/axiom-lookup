<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Superscript\Schema\Types\DictType;
use Superscript\Schema\Types\ListType;
use Superscript\Schema\Types\NumberType;
use Superscript\Schema\Types\StringType;
use Superscript\Schema\Exceptions\TransformValueException;
use Stringable;

use Superscript\Schema\Types\Type;
use function Superscript\Monads\Option\None;

#[CoversClass(DictType::class)]
#[CoversClass(TransformValueException::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(ListType::class)]
class DictTypeTest extends TestCase
{
    #[DataProvider('coerceProvider')]
    #[Test]
    public function it_can_coerce_value(Type $type, mixed $value, array $expected)
    {
        $type = new DictType($type);
        $result = $type->coerce($value);
        $this->assertTrue($result->isOk());
        $this->assertSame($expected, $result->unwrapOr(None())->unwrapOr(null));
    }

    public static function coerceProvider(): array
    {
        return [
            [new NumberType(), ['a' => '1', 'b' => '2', 'c' => '3'], ['a' => 1, 'b' => 2, 'c' => 3]],
            [new NumberType(), '{"a": 1, "b": 2, "c": 3}', ['a' => 1, 'b' => 2, 'c' => 3]],
            [new ListType(new NumberType()), ['a' => ['1', '2', '3'], 'b' => ['4', '5', '6']], ['a' => [1, 2, 3], 'b' => [4, 5, 6]]],
        ];
    }

    #[DataProvider('assertProvider')]
    #[Test]
    public function it_can_assert_value(Type $type, array $value, array $expected)
    {
        $type = new DictType($type);
        $result = $type->assert($value);
        $this->assertTrue($result->isOk());
        $this->assertSame($expected, $result->unwrapOr(None())->unwrapOr(null));
    }

    public static function assertProvider(): array
    {
        return [
            [new NumberType(), ['a' => 1, 'b' => 2, 'c' => 3], ['a' => 1, 'b' => 2, 'c' => 3]],
            [new StringType(), ['x' => 'hello', 'y' => 'world'], ['x' => 'hello', 'y' => 'world']],
        ];
    }

    #[Test]
    public function it_returns_err_if_it_fails_to_coerce()
    {
        $type = new DictType(new NumberType());
        $result = $type->coerce($value = new \stdClass());
        $this->assertEquals($result->unwrapErr(), new TransformValueException(type: 'dict', value: $value));
        $this->assertEquals($result->unwrapErr()->getMessage(), 'Unable to transform into [dict] from [stdClass Object ()]');
    }

    #[Test]
    public function it_returns_err_if_it_fails_to_assert()
    {
        $type = new DictType(new NumberType());
        $result = $type->assert($value = new \stdClass());
        $this->assertEquals($result->unwrapErr(), new TransformValueException(type: 'dict', value: $value));
        $this->assertEquals($result->unwrapErr()->getMessage(), 'Unable to transform into [dict] from [stdClass Object ()]');
    }

    #[DataProvider('compareProvider')]
    #[Test]
    public function it_can_compare_two_values(array $a, array $b, bool $expected)
    {
        $type = new DictType(new NumberType());
        $this->assertSame($expected, $type->compare($a, $b));
    }

    public static function compareProvider(): array
    {
        return [
            [['a' => 1, 'b' => 2], ['a' => 1, 'b' => 2], true],
            [['a' => 1, 'b' => 2], ['a' => '1', 'b' => '2'], false],
            [['a' => 1, 'b' => 2], ['a' => 1, 'c' => 2], false],
        ];
    }

    #[DataProvider('formatProvider')]
    #[Test]
    public function it_can_format_the_value(array $value, string $expected)
    {
        $type = new DictType(new NumberType());
        $this->assertSame($expected, $type->format($value));
    }

    public static function formatProvider(): array
    {
        return [
            [
                ['a' => 1, 'b' => 2, 'c' => 3],
                'a: 1, b: 2, c: 3',
            ],
            [
                ['x' => 10, 'y' => 20],
                'x: 10, y: 20',
            ],
        ];
    }
}
