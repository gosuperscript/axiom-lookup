<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Superscript\Schema\Types\ListType;
use Superscript\Schema\Types\NumberType;
use Superscript\Schema\Types\StringType;
use Superscript\Schema\Exceptions\TransformValueException;
use Stringable;

use Superscript\Schema\Types\Type;
use function Superscript\Monads\Option\None;

#[CoversClass(ListType::class)]
#[CoversClass(TransformValueException::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(StringType::class)]
class ListTypeTest extends TestCase
{
    #[DataProvider('coerceProvider')]
    #[Test]
    public function it_can_coerce_value(Type $type, mixed $value, array $expected)
    {
        $type = new ListType($type);
        $result = $type->coerce($value);
        $this->assertTrue($result->isOk());
        $this->assertSame($expected, $result->unwrapOr(None())->unwrapOr(null));
    }

    public static function coerceProvider(): array
    {
        return [
            [new NumberType(), ['1', '2', '3'], [1, 2, 3]],
            [new NumberType(), '[1, 2, 3]', [1, 2, 3]],
            [new ListType(new NumberType()), [['1', '2', '3'], ['4', '5', '6']], [[1, 2, 3], [4, 5, 6]]],
        ];
    }

    #[DataProvider('assertProvider')]
    #[Test]
    public function it_can_assert_value(Type $type, array $value, array $expected)
    {
        $type = new ListType($type);
        $result = $type->assert($value);
        $this->assertTrue($result->isOk());
        $this->assertSame($expected, $result->unwrapOr(None())->unwrapOr(null));
    }

    public static function assertProvider(): array
    {
        return [
            [new NumberType(), [1, 2, 3], [1, 2, 3]],
            [new StringType(), ['a', 'b', 'c'], ['a', 'b', 'c']],
        ];
    }

    #[Test]
    public function it_returns_err_if_it_fails_to_coerce()
    {
        $type = new ListType(new NumberType());
        $result = $type->coerce($value = new \stdClass());
        $this->assertEquals($result->unwrapErr(), new TransformValueException(type: 'list', value: $value));
        $this->assertEquals($result->unwrapErr()->getMessage(), 'Unable to transform into [list] from [stdClass Object ()]');
    }

    #[Test]
    public function it_returns_err_if_it_fails_to_assert()
    {
        $type = new ListType(new NumberType());
        $result = $type->assert($value = new \stdClass());
        $this->assertEquals($result->unwrapErr(), new TransformValueException(type: 'list', value: $value));
        $this->assertEquals($result->unwrapErr()->getMessage(), 'Unable to transform into [list] from [stdClass Object ()]');
    }

    #[DataProvider('compareProvider')]
    #[Test]
    public function it_can_compare_two_values(array $a, array $b, bool $expected)
    {
        $type = new ListType(new NumberType());
        $this->assertSame($expected, $type->compare($a, $b));
    }

    public static function compareProvider(): array
    {
        return [
            [[1, 2], [1, 2], true],
            [[1, 2], ['1', '2'], false],
        ];
    }

    #[DataProvider('formatProvider')]
    #[Test]
    public function it_can_format_the_value(array $value, string $expected)
    {
        $type = new ListType(new ListType(new NumberType()));
        $this->assertSame($expected, $type->format($value));
    }

    public static function formatProvider(): array
    {
        return [
            [[[1, 2], [3, 4]], '1, 2, 3, 4'],
        ];
    }
}
