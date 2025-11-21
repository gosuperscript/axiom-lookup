<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Superscript\Schema\Types\StringType;
use Superscript\Schema\Exceptions\TransformValueException;
use Stringable;

use function Superscript\Monads\Option\None;

#[CoversClass(StringType::class)]
#[CoversClass(TransformValueException::class)]
class StringTypeTest extends TestCase
{
    #[DataProvider('coerceProvider')]
    #[Test]
    public function it_can_coerce_value(mixed $value, ?string $expected)
    {
        $type = new StringType();
        $result = $type->coerce($value);
        $this->assertTrue($result->isOk());
        $this->assertSame($expected, $result->unwrapOr(None())->unwrapOr(null));
    }

    public static function coerceProvider(): array
    {
        return [
            ['hello', 'hello'],
            [1, '1'],
            [1.1, '1.1'],
            ['', null],
            ['null', null],
            [new class implements Stringable {
                public function __toString(): string
                {
                    return 'hello';
                }
            }, 'hello'],
        ];
    }

    #[DataProvider('assertProvider')]
    #[Test]
    public function it_can_assert_value(string $value, ?string $expected)
    {
        $type = new StringType();
        $result = $type->assert($value);
        $this->assertTrue($result->isOk());
        $this->assertSame($expected, $result->unwrapOr(None())->unwrapOr(null));
    }

    public static function assertProvider(): array
    {
        return [
            ['hello', 'hello'],
            ['', null],
            ['null', null],
        ];
    }

    #[Test]
    public function it_returns_err_if_it_fails_to_coerce()
    {
        $type = new StringType();
        $result = $type->coerce($value = new \stdClass());
        $this->assertEquals($result->unwrapErr(), new TransformValueException(type: 'string', value: $value));
        $this->assertEquals($result->unwrapErr()->getMessage(), 'Unable to transform into [string] from [stdClass Object ()]');
    }

    #[Test]
    public function it_returns_err_if_it_fails_to_assert()
    {
        $type = new StringType();
        $result = $type->assert($value = 123);
        $this->assertEquals($result->unwrapErr(), new TransformValueException(type: 'string', value: $value));
        $this->assertEquals($result->unwrapErr()->getMessage(), 'Unable to transform into [string] from [123]');
    }

    #[DataProvider('compareProvider')]
    #[Test]
    public function it_can_compare_two_values(string $a, string $b, bool $expected)
    {
        $type = new StringType();
        $this->assertSame($expected, $type->compare($a, $b));
    }

    public static function compareProvider(): array
    {
        return [
            ['hello', 'hello', true],
            ['hello', 'world', false],
        ];
    }

    #[DataProvider('formatProvider')]
    #[Test]
    public function it_can_format_the_value(string $value, string $expected)
    {
        $type = new StringType();
        $this->assertSame($expected, $type->format($value));
    }

    public static function formatProvider(): array
    {
        return [
            ['hello', 'hello'],
            ['world', 'world'],
            ['', ''],
        ];
    }
}
