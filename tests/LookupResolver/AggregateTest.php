<?php

declare(strict_types=1);

namespace Superscript\Schema\Lookup\Tests\LookupResolver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Schema\Lookup\CsvRecord;
use Superscript\Schema\Lookup\Support\Aggregates\Avg;
use Superscript\Schema\Lookup\Support\Aggregates\Count;
use Superscript\Schema\Lookup\Support\Aggregates\First;
use Superscript\Schema\Lookup\Support\Aggregates\Last;
use Superscript\Schema\Lookup\Support\Aggregates\Max;
use Superscript\Schema\Lookup\Support\Aggregates\Min;
use Superscript\Schema\Lookup\Support\Aggregates\Sum;

#[CoversClass(First::class)]
#[CoversClass(Last::class)]
#[CoversClass(Count::class)]
#[CoversClass(Sum::class)]
#[CoversClass(Avg::class)]
#[CoversClass(Min::class)]
#[CoversClass(Max::class)]
#[UsesClass(CsvRecord::class)]
class AggregateTest extends TestCase
{
    #[Test]
    public function first_aggregate_stores_first_record(): void
    {
        $state = First::initial();
        $record1 = CsvRecord::from(['name' => 'Alice']);
        $record2 = CsvRecord::from(['name' => 'Bob']);
        
        $state = $state->process($record1, null);
        $state = $state->process($record2, null);
        
        $result = $state->finalize('name');
        self::assertSame('Alice', $result);
    }

    #[Test]
    public function first_aggregate_can_early_exit(): void
    {
        $state = First::initial();
        $record = CsvRecord::from(['name' => 'Alice']);
        
        self::assertFalse($state->canEarlyExit());
        
        $state = $state->process($record, null);
        
        self::assertTrue($state->canEarlyExit());
    }

    #[Test]
    public function last_aggregate_stores_last_record(): void
    {
        $state = Last::initial();
        $record1 = CsvRecord::from(['name' => 'Alice']);
        $record2 = CsvRecord::from(['name' => 'Bob']);
        
        $state = $state->process($record1, null);
        $state = $state->process($record2, null);
        
        $result = $state->finalize('name');
        self::assertSame('Bob', $result);
    }

    #[Test]
    public function last_aggregate_cannot_early_exit(): void
    {
        $state = Last::initial();
        $record = CsvRecord::from(['name' => 'Alice']);
        
        $state = $state->process($record, null);
        
        self::assertFalse($state->canEarlyExit());
    }

    #[Test]
    public function count_aggregate_counts_records(): void
    {
        $state = Count::initial();
        $record = CsvRecord::from(['name' => 'Alice']);
        
        $state = $state->process($record, null);
        $state = $state->process($record, null);
        $state = $state->process($record, null);
        
        self::assertSame(3, $state->finalize([]));
    }

    #[Test]
    public function count_aggregate_ignores_column(): void
    {
        $state = Count::initial();
        $record = CsvRecord::from(['name' => 'Alice', 'age' => 25]);
        
        // Column is ignored for count
        $state = $state->process($record, 'age');
        
        self::assertSame(1, $state->finalize([]));
    }

    #[Test]
    public function sum_aggregate_sums_numeric_values(): void
    {
        $state = Sum::initial();
        $record1 = CsvRecord::from(['price' => '10.5']);
        $record2 = CsvRecord::from(['price' => '20.25']);
        $record3 = CsvRecord::from(['price' => '5']);
        
        $state = $state->process($record1, 'price');
        $state = $state->process($record2, 'price');
        $state = $state->process($record3, 'price');
        
        self::assertSame(35.75, $state->finalize([]));
    }

    #[Test]
    public function sum_aggregate_ignores_non_numeric(): void
    {
        $state = Sum::initial();
        $record1 = CsvRecord::from(['price' => '10']);
        $record2 = CsvRecord::from(['price' => 'invalid']);
        $record3 = CsvRecord::from(['price' => '5']);
        
        $state = $state->process($record1, 'price');
        $state = $state->process($record2, 'price');
        $state = $state->process($record3, 'price');
        
        self::assertSame(15.0, $state->finalize([]));
    }

    #[Test]
    public function sum_aggregate_returns_null_when_no_values(): void
    {
        $state = Sum::initial();
        
        self::assertNull($state->finalize([]));
    }

    #[Test]
    public function avg_aggregate_calculates_average(): void
    {
        $state = Avg::initial();
        $record1 = CsvRecord::from(['score' => '10']);
        $record2 = CsvRecord::from(['score' => '20']);
        $record3 = CsvRecord::from(['score' => '30']);
        
        $state = $state->process($record1, 'score');
        $state = $state->process($record2, 'score');
        $state = $state->process($record3, 'score');
        
        self::assertSame(20.0, $state->finalize([]));
    }

    #[Test]
    public function avg_aggregate_ignores_non_numeric(): void
    {
        $state = Avg::initial();
        $record1 = CsvRecord::from(['score' => '10']);
        $record2 = CsvRecord::from(['score' => 'invalid']);
        $record3 = CsvRecord::from(['score' => '30']);
        
        $state = $state->process($record1, 'score');
        $state = $state->process($record2, 'score');
        $state = $state->process($record3, 'score');
        
        self::assertSame(20.0, $state->finalize([]));
    }

    #[Test]
    public function avg_aggregate_returns_null_when_no_values(): void
    {
        $state = Avg::initial();
        
        self::assertNull($state->finalize([]));
    }

    #[Test]
    public function min_aggregate_finds_minimum(): void
    {
        $state = Min::initial();
        $record1 = CsvRecord::from(['price' => '25', 'name' => 'Alice']);
        $record2 = CsvRecord::from(['price' => '10', 'name' => 'Bob']);
        $record3 = CsvRecord::from(['price' => '30', 'name' => 'Charlie']);
        
        $state = $state->process($record1, 'price');
        $state = $state->process($record2, 'price');
        $state = $state->process($record3, 'price');
        
        $result = $state->finalize(['price', 'name']);
        self::assertSame(['price' => '10', 'name' => 'Bob'], $result);
    }

    #[Test]
    public function min_aggregate_ignores_non_numeric(): void
    {
        $state = Min::initial();
        $record1 = CsvRecord::from(['price' => '25', 'name' => 'Alice']);
        $record2 = CsvRecord::from(['price' => '10', 'name' => 'Bob']);
        
        $state = $state->process($record1, 'price');
        $state = $state->process($record2, 'price');
        
        $result = $state->finalize(['price', 'name']);
        self::assertSame(['price' => '10', 'name' => 'Bob'], $result);
    }

    #[Test]
    public function min_aggregate_returns_null_when_no_records(): void
    {
        $state = Min::initial();
        
        self::assertNull($state->finalize('price'));
    }

    #[Test]
    public function max_aggregate_finds_maximum(): void
    {
        $state = Max::initial();
        $record1 = CsvRecord::from(['price' => '25', 'name' => 'Alice']);
        $record2 = CsvRecord::from(['price' => '10', 'name' => 'Bob']);
        $record3 = CsvRecord::from(['price' => '30', 'name' => 'Charlie']);
        
        $state = $state->process($record1, 'price');
        $state = $state->process($record2, 'price');
        $state = $state->process($record3, 'price');
        
        $result = $state->finalize(['price', 'name']);
        self::assertSame(['price' => '30', 'name' => 'Charlie'], $result);
    }

    #[Test]
    public function max_aggregate_ignores_non_numeric(): void
    {
        $state = Max::initial();
        $record1 = CsvRecord::from(['price' => '25', 'name' => 'Alice']);
        $record2 = CsvRecord::from(['price' => '30', 'name' => 'Bob']);
        
        $state = $state->process($record1, 'price');
        $state = $state->process($record2, 'price');
        
        $result = $state->finalize(['price', 'name']);
        self::assertSame(['price' => '30', 'name' => 'Bob'], $result);
    }

    #[Test]
    public function max_aggregate_returns_null_when_no_records(): void
    {
        $state = Max::initial();
        
        self::assertNull($state->finalize('price'));
    }

    #[Test]
    public function aggregate_states_cannot_early_exit_by_default(): void
    {
        self::assertFalse(Last::initial()->canEarlyExit());
        self::assertFalse(Count::initial()->canEarlyExit());
        self::assertFalse(Sum::initial()->canEarlyExit());
        self::assertFalse(Avg::initial()->canEarlyExit());
        self::assertFalse(Min::initial()->canEarlyExit());
        self::assertFalse(Max::initial()->canEarlyExit());
    }
}
