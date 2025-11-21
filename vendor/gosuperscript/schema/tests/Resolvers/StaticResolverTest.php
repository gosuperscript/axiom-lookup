<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests\Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Schema\Resolvers\StaticResolver;
use Superscript\Schema\Source;
use Superscript\Schema\Sources\StaticSource;

#[CoversClass(StaticResolver::class)]
#[CoversClass(StaticSource::class)]
class StaticResolverTest extends TestCase
{
    #[Test]
    public function it_resolves(): void
    {
        $resolver = new StaticResolver();
        $source = new StaticSource('Hello world!');
        $this->assertEquals('Hello world!', $resolver->resolve($source)->unwrap()->unwrap());
    }
}
