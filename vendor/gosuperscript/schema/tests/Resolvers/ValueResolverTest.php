<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests\Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Schema\Resolvers\Resolver;
use Superscript\Schema\Source;
use Superscript\Schema\Sources\ValueDefinition;
use Superscript\Schema\Types\NumberType;
use Superscript\Schema\Types\StringType;
use Superscript\Schema\Resolvers\ValueResolver;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

#[CoversClass(ValueResolver::class)]
#[CoversClass(ValueDefinition::class)]
#[UsesClass(StringType::class)]
class ValueResolverTest extends TestCase
{
    #[Test]
    public function it_can_resolve_a_value()
    {
        $resolver = new ValueResolver(new class implements Resolver {
            public function resolve(Source $source): Result
            {
                return Ok(Some('Hello, World!'));
            }
        });
        $source = new ValueDefinition(new StringType(), new class implements Source {});

        $result = $resolver->resolve($source);
        $this->assertInstanceOf(Result::class, $result);
        $this->assertEquals('Hello, World!', $result->unwrap()->unwrap());
    }
}
