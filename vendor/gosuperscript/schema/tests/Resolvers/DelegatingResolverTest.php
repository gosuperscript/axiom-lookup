<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests\Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Schema\Resolvers\DelegatingResolver;
use Superscript\Schema\Resolvers\StaticResolver;
use Superscript\Schema\Resolvers\ValueResolver;
use Superscript\Schema\Sources\StaticSource;
use Superscript\Schema\Sources\ValueDefinition;
use Superscript\Schema\Tests\Resolvers\Fixtures\Dependency;
use Superscript\Schema\Tests\Resolvers\Fixtures\ResolverWithDependency;
use Superscript\Schema\Types\NumberType;

#[CoversClass(DelegatingResolver::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(StaticResolver::class)]
#[UsesClass(ValueResolver::class)]
#[UsesClass(ValueDefinition::class)]
#[UsesClass(NumberType::class)]
class DelegatingResolverTest extends TestCase
{
    #[Test]
    public function it_can_resolve_by_delegating_to_another_resolver(): void
    {
        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
        ]);

        $result = $resolver->resolve(new StaticSource('Hello world!'));
        $this->assertEquals('Hello world!', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_supports_resolvers_depending_on_other_resolvers(): void
    {
        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            ValueDefinition::class => ValueResolver::class,
        ]);

        $result = $resolver->resolve(new ValueDefinition(new NumberType(), new StaticSource('42')));
        $this->assertEquals(42, $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_supports_resolvers_with_dependencies(): void
    {
        $resolver = new DelegatingResolver([
            StaticSource::class => ResolverWithDependency::class,
        ]);

        $resolver->instance(Dependency::class, new Dependency('hello'));

        $this->assertEquals('hello', $resolver->resolve(new StaticSource(42))->unwrap()->unwrap());
    }

    #[Test]
    public function it_throws_an_exception_if_no_resolver_can_handle_the_source(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No resolver found for source of type ' . StaticSource::class);

        $resolver = new DelegatingResolver([]);
        $resolver->resolve(new StaticSource('Hello world!'));
    }
}
