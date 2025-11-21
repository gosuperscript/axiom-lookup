<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests\Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Schema\Resolvers\StaticResolver;
use Superscript\Schema\Resolvers\SymbolResolver;
use Superscript\Schema\Source;
use Superscript\Schema\Sources\StaticSource;
use Superscript\Schema\Sources\SymbolSource;
use Superscript\Schema\SymbolRegistry;
use Superscript\Monads\Result\Result;

#[CoversClass(SymbolResolver::class)]
#[CoversClass(SymbolSource::class)]
#[CoversClass(SymbolRegistry::class)]
#[UsesClass(StaticResolver::class)]
#[UsesClass(StaticSource::class)]
class SymbolResolverTest extends TestCase
{
    #[Test]
    public function it_can_resolve_a_value(): void
    {
        $resolver = new SymbolResolver(new StaticResolver(), new SymbolRegistry([
            'A' => new StaticSource(2),
        ]));
        $source = new SymbolSource('A');
        $result = $resolver->resolve($source);
        $this->assertInstanceOf(Result::class, $result);
        $this->assertEquals(2, $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_resolve_a_namespaced_symbol(): void
    {
        $resolver = new SymbolResolver(new StaticResolver(), new SymbolRegistry([
            'math' => [
                'pi' => new StaticSource(3.14),
                'e' => new StaticSource(2.71),
            ],
        ]));

        $source = new SymbolSource('pi', 'math');
        $result = $resolver->resolve($source);
        $this->assertInstanceOf(Result::class, $result);
        $this->assertEquals(3.14, $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_none_for_nonexistent_namespaced_symbol(): void
    {
        $resolver = new SymbolResolver(new StaticResolver(), new SymbolRegistry([
            'math' => [
                'pi' => new StaticSource(3.14),
            ],
        ]));

        // Wrong namespace
        $source = new SymbolSource('pi', 'physics');
        $result = $resolver->resolve($source);
        $this->assertInstanceOf(Result::class, $result);
        $this->assertTrue($result->unwrap()->isNone());
    }

    #[Test]
    public function it_distinguishes_between_namespaced_and_non_namespaced_symbols(): void
    {
        $resolver = new SymbolResolver(new StaticResolver(), new SymbolRegistry([
            'value' => new StaticSource(10),
            'ns' => [
                'value' => new StaticSource(20),
            ],
        ]));

        // Resolve without namespace
        $source = new SymbolSource('value');
        $result = $resolver->resolve($source);
        $this->assertEquals(10, $result->unwrap()->unwrap());

        // Resolve with namespace
        $source = new SymbolSource('value', 'ns');
        $result = $resolver->resolve($source);
        $this->assertEquals(20, $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_preserves_backward_compatibility_with_null_namespace(): void
    {
        $resolver = new SymbolResolver(new StaticResolver(), new SymbolRegistry([
            'A' => new StaticSource(42),
        ]));

        // SymbolSource with null namespace (default)
        $source = new SymbolSource('A', null);
        $result = $resolver->resolve($source);
        $this->assertEquals(42, $result->unwrap()->unwrap());
    }
}
