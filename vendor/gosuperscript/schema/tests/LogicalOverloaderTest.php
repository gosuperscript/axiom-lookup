<?php

declare(strict_types=1);


use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Schema\Operators\DefaultOverloader;
use Superscript\Schema\Operators\LogicalOverloader;

#[CoversClass(LogicalOverloader::class)]
class LogicalOverloaderTest extends TestCase
{
    #[DataProvider('cases')]
    #[Test]
    public function it_evaluates(mixed $left, string $operator, mixed $right, mixed $expected): void
    {
        $overloader = new LogicalOverloader();
        $this->assertTrue($overloader->supportsOverloading(left: $left, right: $right, operator: $operator));
        $this->assertEquals($expected, $overloader->evaluate(left: $left, right: $right, operator: $operator));
    }

    public static function cases(): Generator
    {
        yield [true, '&&', true, true];
        yield [true, '&&', false, false];
        yield [false, '&&', true, false];
        yield [false, '&&', false, false];
        yield [true, '||', true, true];
        yield [true, '||', false, true];
        yield [false, '||', true, true];
        yield [false, '||', false, false];
        yield [true, 'xor', true, false];
        yield [true, 'xor', false, true];
    }

    #[Test]
    #[DataProvider('invalidCases')]
    public function it_does_not_support_invalid_cases(mixed $left, string $operator, mixed $right): void
    {
        $overloader = new LogicalOverloader();
        $this->assertFalse($overloader->supportsOverloading($left, $right, $operator));
    }

    public static function invalidCases(): Generator
    {
        yield [1, '&&', 2];
        yield [1, '||', 2];
        yield [1, 'xor', 2];
        yield [new stdClass(), '&&', new stdClass()];
        yield [new stdClass(), '||', new stdClass()];
        yield [new stdClass(), 'xor', new stdClass()];
        yield [1, '>', 2];
        yield [true, '>', 2];
        yield [2, '>', true];
        yield [2, '&&', true];
    }
}
