<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Dsl\FunctionRegistry;
use Superscript\Axiom\Lookup\Dsl\LookupDslPlugin;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Sources\StaticSource;

#[CoversNothing]
class LookupDslIntegrationTest extends TestCase
{
    #[Test]
    public function it_registers_lookup_function(): void
    {
        $plugin = new LookupDslPlugin();
        $registry = new FunctionRegistry();

        $plugin->functions($registry);

        $this->assertTrue($registry->has('lookup'));

        $entry = $registry->resolve('lookup');

        $this->assertNotNull($entry);
        $this->assertCount(2, $entry->params);

        $this->assertSame('path', $entry->params[0]->name);
        $this->assertSame('string', $entry->params[0]->type);

        $this->assertSame('column', $entry->params[1]->name);
        $this->assertSame('string', $entry->params[1]->type);
    }

    #[Test]
    public function it_creates_lookup_source_from_function_call(): void
    {
        $plugin = new LookupDslPlugin();
        $registry = new FunctionRegistry();

        $plugin->functions($registry);

        $entry = $registry->resolve('lookup');
        $this->assertNotNull($entry);

        $compiler = new class {
            public function expectStaticString(mixed $node): string
            {
                return $node;
            }

            public function compile(mixed $node): StaticSource
            {
                return new StaticSource($node);
            }
        };

        $args = [0 => 'data/rates.csv', 'column' => 'rate'];

        $result = ($entry->factory)($args, $compiler);

        $this->assertInstanceOf(LookupSource::class, $result);
        $this->assertSame('data/rates.csv', $result->path);
        $this->assertSame(['rate'], $result->columns);
        $this->assertEmpty($result->filters);
    }

    #[Test]
    public function it_creates_lookup_source_with_filters(): void
    {
        $plugin = new LookupDslPlugin();
        $registry = new FunctionRegistry();

        $plugin->functions($registry);

        $entry = $registry->resolve('lookup');
        $this->assertNotNull($entry);

        $regionNode = new \stdClass();

        $compiler = new class ($regionNode) {
            public function __construct(private readonly \stdClass $regionNode) {}

            public function expectStaticString(mixed $node): string
            {
                return $node;
            }

            public function compile(mixed $node): StaticSource
            {
                if ($node === $this->regionNode) {
                    return new StaticSource('EU');
                }

                return new StaticSource($node);
            }
        };

        $args = [0 => 'data/rates.csv', 'column' => 'rate', 'region' => $regionNode];

        $result = ($entry->factory)($args, $compiler);

        $this->assertInstanceOf(LookupSource::class, $result);
        $this->assertSame('data/rates.csv', $result->path);
        $this->assertSame(['rate'], $result->columns);
        $this->assertCount(1, $result->filters);

        $filter = $result->filters[0];
        $this->assertInstanceOf(ValueFilter::class, $filter);
        $this->assertSame('region', $filter->column);
        $this->assertInstanceOf(StaticSource::class, $filter->value);
        $this->assertSame('EU', $filter->value->value);
    }
}
