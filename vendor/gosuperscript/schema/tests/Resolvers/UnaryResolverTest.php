<?php

namespace Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Schema\Operators\BinaryOverloader;
use Superscript\Schema\Operators\DefaultOverloader;
use Superscript\Schema\Operators\OverloaderManager;
use Superscript\Schema\Resolvers\InfixResolver;
use Superscript\Schema\Resolvers\StaticResolver;
use Superscript\Schema\Resolvers\UnaryResolver;
use Superscript\Schema\Sources\InfixExpression;
use Superscript\Schema\Sources\StaticSource;
use Superscript\Schema\Sources\UnaryExpression;

#[CoversClass(UnaryExpression::class)]
#[CoversClass(UnaryResolver::class)]
#[UsesClass(StaticResolver::class)]
#[UsesClass(StaticSource::class)]
class UnaryResolverTest extends TestCase
{
    #[Test]
    public function it_can_resolve_a_logical_not_expression(): void
    {
        $resolver = new UnaryResolver(new StaticResolver());
        $source = new UnaryExpression(
            operator: '!',
            operand: new StaticSource(true)
        );

        $this->assertEquals(false, $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_resolve_a_unary_minus_expression(): void
    {
        $resolver = new UnaryResolver(new StaticResolver());
        $source = new UnaryExpression(
            operator: '-',
            operand: new StaticSource(42)
        );

        $this->assertEquals(-42, $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_err_for_unsupported_operators(): void
    {
        $resolver = new UnaryResolver(new StaticResolver());
        $source = new UnaryExpression(
            operator: '+',
            operand: new StaticSource(42)
        );

        $this->assertTrue($resolver->resolve($source)->isErr());
    }
}
