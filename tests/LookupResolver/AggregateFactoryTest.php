<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Tests\LookupResolver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Superscript\Axiom\Lookup\Support\Aggregates\Aggregate;
use Superscript\Axiom\Lookup\Support\Aggregates\AggregateFactory;
use Superscript\Axiom\Lookup\Support\Aggregates\AggregateKind;
use Superscript\Axiom\Lookup\Support\Aggregates\All;
use Superscript\Axiom\Lookup\Support\Aggregates\Avg;
use Superscript\Axiom\Lookup\Support\Aggregates\Count;
use Superscript\Axiom\Lookup\Support\Aggregates\First;
use Superscript\Axiom\Lookup\Support\Aggregates\Last;
use Superscript\Axiom\Lookup\Support\Aggregates\Max;
use Superscript\Axiom\Lookup\Support\Aggregates\Min;
use Superscript\Axiom\Lookup\Support\Aggregates\Sum;

#[CoversClass(AggregateFactory::class)]
#[UsesClass(AggregateKind::class)]
#[UsesClass(First::class)]
#[UsesClass(Last::class)]
#[UsesClass(Count::class)]
#[UsesClass(Sum::class)]
#[UsesClass(Avg::class)]
#[UsesClass(Min::class)]
#[UsesClass(Max::class)]
#[UsesClass(All::class)]
class AggregateFactoryTest extends TestCase
{
    public static function aggregates(): iterable
    {
        yield 'first' => ['first', First::class];
        yield 'last' => ['last', Last::class];
        yield 'count' => ['count', Count::class];
        yield 'sum' => ['sum', Sum::class];
        yield 'avg' => ['avg', Avg::class];
        yield 'min' => ['min', Min::class];
        yield 'max' => ['max', Max::class];
        yield 'all' => ['all', All::class];
    }

    #[Test]
    #[DataProvider('aggregates')]
    public function it_creates_aggregate(string $name, string $class): void
    {
        self::assertInstanceOf($class, AggregateFactory::for($name));
    }

    #[Test]
    public function every_name_the_vocabulary_lists_is_a_name_the_factory_accepts(): void
    {
        foreach (AggregateKind::names() as $name) {
            self::assertInstanceOf(Aggregate::class, AggregateFactory::for($name));
        }
    }

    #[Test]
    public function it_throws_for_unknown_aggregate(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown aggregate: unknown');

        AggregateFactory::for('unknown');
    }
}
