<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Tests\LookupResolver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Superscript\Axiom\Lookup\Support\Aggregates\AggregateFactory;
use Superscript\Axiom\Lookup\Support\Aggregates\All;
use Superscript\Axiom\Lookup\Support\Aggregates\Avg;
use Superscript\Axiom\Lookup\Support\Aggregates\Count;
use Superscript\Axiom\Lookup\Support\Aggregates\First;
use Superscript\Axiom\Lookup\Support\Aggregates\Last;
use Superscript\Axiom\Lookup\Support\Aggregates\Max;
use Superscript\Axiom\Lookup\Support\Aggregates\Min;
use Superscript\Axiom\Lookup\Support\Aggregates\Sum;

#[CoversClass(AggregateFactory::class)]
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
    #[Test]
    public function it_creates_first_aggregate(): void
    {
        self::assertInstanceOf(First::class, AggregateFactory::for('first'));
    }

    #[Test]
    public function it_creates_last_aggregate(): void
    {
        self::assertInstanceOf(Last::class, AggregateFactory::for('last'));
    }

    #[Test]
    public function it_creates_count_aggregate(): void
    {
        self::assertInstanceOf(Count::class, AggregateFactory::for('count'));
    }

    #[Test]
    public function it_creates_sum_aggregate(): void
    {
        self::assertInstanceOf(Sum::class, AggregateFactory::for('sum'));
    }

    #[Test]
    public function it_creates_avg_aggregate(): void
    {
        self::assertInstanceOf(Avg::class, AggregateFactory::for('avg'));
    }

    #[Test]
    public function it_creates_min_aggregate(): void
    {
        self::assertInstanceOf(Min::class, AggregateFactory::for('min'));
    }

    #[Test]
    public function it_creates_max_aggregate(): void
    {
        self::assertInstanceOf(Max::class, AggregateFactory::for('max'));
    }

    #[Test]
    public function it_creates_all_aggregate(): void
    {
        self::assertInstanceOf(All::class, AggregateFactory::for('all'));
    }

    #[Test]
    public function it_throws_for_unknown_aggregate(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown aggregate: unknown');

        AggregateFactory::for('unknown');
    }
}
