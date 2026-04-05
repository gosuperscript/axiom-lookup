<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Tests\Dsl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Dsl\FunctionRegistry;
use Superscript\Axiom\Dsl\OperatorRegistry;
use Superscript\Axiom\Dsl\TypeRegistry;
use Superscript\Axiom\Lookup\Dsl\LookupDslPlugin;

#[CoversClass(LookupDslPlugin::class)]
class LookupDslPluginTest extends TestCase
{
    #[Test]
    public function it_has_no_operators(): void
    {
        $plugin = new LookupDslPlugin();
        $registry = new OperatorRegistry();

        $plugin->operators($registry);

        $this->assertEmpty($registry->all());
    }

    #[Test]
    public function it_has_no_types(): void
    {
        $plugin = new LookupDslPlugin();
        $registry = new TypeRegistry();

        $plugin->types($registry);

        $this->assertEmpty($registry->all());
    }

    #[Test]
    public function it_registers_the_lookup_function(): void
    {
        $plugin = new LookupDslPlugin();
        $registry = new FunctionRegistry();

        $plugin->functions($registry);

        $this->assertTrue($registry->has('lookup'));

        $entry = $registry->resolve('lookup');
        $this->assertNotNull($entry);
        $this->assertSame('lookup', $entry->name);
        $this->assertCount(2, $entry->params);
        $this->assertSame('path', $entry->params[0]->name);
        $this->assertSame('column', $entry->params[1]->name);
    }

    #[Test]
    public function it_has_no_patterns(): void
    {
        $plugin = new LookupDslPlugin();

        $this->assertEmpty($plugin->patterns());
    }

    #[Test]
    public function it_has_no_literals(): void
    {
        $plugin = new LookupDslPlugin();

        $this->assertEmpty($plugin->literals());
    }

    #[Test]
    public function it_has_no_overloaders(): void
    {
        $plugin = new LookupDslPlugin();

        $this->assertEmpty($plugin->overloaders());
    }
}
