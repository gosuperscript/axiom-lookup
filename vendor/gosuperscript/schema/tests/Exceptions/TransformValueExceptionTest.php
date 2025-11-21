<?php

namespace Superscript\Schema\Tests\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Superscript\Schema\Exceptions\TransformValueException;
use PHPUnit\Framework\TestCase;

#[CoversClass(TransformValueException::class)]
class TransformValueExceptionTest extends TestCase
{
    #[Test]
    public function it_can_format_a_value(): void
    {
        $value = 'test value';
        $formatted = TransformValueException::format($value);
        $this->assertEquals("'test value'", $formatted);
    }

    #[Test]
    public function it_can_format_a_value_that_implements_stringable(): void
    {
        $value = new class() implements \Stringable {
            public function __toString(): string
            {
                return 'stringable value';
            }
        };

        $formatted = TransformValueException::format($value);
        $this->assertEquals("'stringable value'", $formatted);
    }
}
