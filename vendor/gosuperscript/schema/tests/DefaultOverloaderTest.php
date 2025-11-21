<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests;

use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Superscript\Schema\Operators\BinaryOverloader;
use Superscript\Schema\Operators\ComparisonOverloader;
use Superscript\Schema\Operators\DefaultOverloader;
use Superscript\Schema\Operators\HasOverloader;
use Superscript\Schema\Operators\InOverloader;
use Superscript\Schema\Operators\LogicalOverloader;
use Superscript\Schema\Operators\IntersectsOverloader;

#[CoversClass(DefaultOverloader::class)]
#[CoversClass(BinaryOverloader::class)]
#[CoversClass(ComparisonOverloader::class)]
#[CoversClass(HasOverloader::class)]
#[CoversClass(InOverloader::class)]
#[CoversClass(LogicalOverloader::class)]
#[CoversClass(IntersectsOverloader::class)]
class DefaultOverloaderTest extends TestCase
{
    #[Test]
    #[DataProvider('cases')]
    public function it_evaluates(mixed $left, string $operator, mixed $right, mixed $expected): void
    {
        $overloader = new DefaultOverloader();
        $this->assertTrue($overloader->supportsOverloading(left: $left, right: $right, operator: $operator));
        $this->assertEquals($expected, $overloader->evaluate(left: $left, right: $right, operator: $operator));
    }

    public static function cases(): Generator
    {
        yield [1, '+', 2, 3];
        yield [3, '-', 2, 1];
        yield [2, '*', 3, 6];

        yield [6, '/', 3, 2];

        yield [1, '>', 2, false];
        yield [2, '>', 2, false];
        yield [3, '>', 2, true];

        yield [1, '>=', 2, false];
        yield [2, '>=', 2, true];
        yield [3, '>=', 2, true];

        yield [1, '<', 2, true];
        yield [2, '<', 2, false];
        yield [3, '<', 2, false];

        yield [1, '<=', 2, true];
        yield [2, '<=', 2, true];
        yield [3, '<=', 2, false];

        yield [1, '=', 1, true];
        yield [1, '=', 2, false];
        yield [1, '!=', 2, true];
        yield [1, '!=', 1, false];
        yield [1, '==', '1', true];

        yield [1, '===', 1, true];
        yield [1, '===', '1', false];
        yield [1, '!==', 2, true];

        yield [['a', 'b'], 'has', 'a', true];
        yield [['a', 'b'], 'has', 'c', false];
        yield [['a', 'b'], 'has', ['a', 'b'], true];
        yield [['a', 'b'], 'has', ['a', 'c'], false];
        yield [['a', 'b', 'c'], 'has', ['a', 'c'], true];

        yield ['a', 'in', ['a', 'b'], true];
        yield ['c', 'in', ['a', 'b'], false];
        yield [['a', 'b'], 'in', ['a', 'b'], true];
        yield [['a', 'b'], 'in', ['a', 'c'], false];
        yield [['a', 'c'], 'in', ['a', 'b', 'c'], true];
        yield [['a', 'b', 'c'], 'in', ['a', 'c'], false];
        yield [['a', 'b', 'd'], 'in', ['a', 'b', 'c'], false];

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

        yield ['a', 'intersects', ['a', 'b'], true];
        yield ['c', 'intersects', ['a', 'b'], false];
        yield [['a', 'b'], 'intersects', ['a'], true];
        yield [['a', 'b'], 'intersects', ['c'], false];
        yield [['a', 'b'], 'intersects', ['a', 'c'], true];
        yield [['a', 'b'], 'intersects', ['c', 'd'], false];
        yield ['a', 'intersects', 'a', true];
        yield ['a', 'intersects', 'b', false];
    }

    #[Test]
    public function it_does_not_support_objects(): void
    {
        $overloader = new DefaultOverloader();
        $this->assertFalse($overloader->supportsOverloading(new stdClass(), new stdClass(), '+'));
        $this->assertFalse($overloader->supportsOverloading(1, new stdClass(), '+'));
        $this->assertFalse($overloader->supportsOverloading(new stdClass(), 1, '+'));
    }

    #[Test]
    public function it_throws_error_for_unsupported_operators(): void
    {
        $this->expectExceptionMessage('Operator [foo] is not supported.');
        (new DefaultOverloader())->evaluate(1, 1, 'foo');
    }
}
