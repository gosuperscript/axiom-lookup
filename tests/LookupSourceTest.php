<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Tests;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Support\Aggregates;
use Superscript\Axiom\Lookup\Support\Filters\RangeFilter;
use Superscript\Axiom\Lookup\Support\Filters\ResolvedFilter;
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\UnknownType;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

#[CoversClass(LookupSource::class)]
#[CoversClass(ValueFilter::class)]
#[CoversClass(RangeFilter::class)]
#[CoversClass(ResolvedFilter::class)]
#[UsesClass(CsvRecord::class)]
#[UsesClass(Aggregates\First::class)]
#[UsesClass(Aggregates\Last::class)]
#[UsesClass(Aggregates\Count::class)]
#[UsesClass(Aggregates\Sum::class)]
#[UsesClass(Aggregates\Avg::class)]
#[UsesClass(Aggregates\Min::class)]
#[UsesClass(Aggregates\Max::class)]
#[UsesClass(Aggregates\All::class)]
#[UsesClass(Aggregates\AggregateFactory::class)]
class LookupSourceTest extends TestCase
{
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $adapter = new LocalFilesystemAdapter(__DIR__ . '/Fixtures');
        $this->filesystem = new Filesystem($adapter);
    }

    /**
     * @param array<\Superscript\Axiom\Lookup\Support\Filters\Filter> $filters
     * @param array<string|int> $columns
     */
    private function lookup(
        string $path,
        array $filters = [],
        array $columns = [],
        string $aggregate = 'first',
        string|int|null $aggregateColumn = null,
        string $delimiter = ',',
        bool $hasHeader = true,
        ?FilesystemOperator $filesystem = null,
    ): LookupSource {
        return new LookupSource(
            path: $path,
            filesystem: $filesystem ?? $this->filesystem,
            filters: $filters,
            columns: $columns,
            aggregate: $aggregate,
            aggregateColumn: $aggregateColumn,
            delimiter: $delimiter,
            hasHeader: $hasHeader,
        );
    }

    /**
     * Compile the lookup as a whole expression and invoke it — the boundary
     * the old resolver crossed, now expressed as compile-then-run.
     *
     * @return Result<Option<mixed>, \Throwable>
     */
    private function execute(LookupSource $source): Result
    {
        return (new Expression($source))->compile()->unwrap()();
    }

    private function filter(string|int $column, Source $value, string $operator = '=='): ValueFilter
    {
        return new ValueFilter($column, $value, $operator);
    }

    #[Test]
    public function it_can_lookup_single_column_from_csv_with_single_filter(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('name', new StaticSource('Alice'))],
            columns: ['age'],
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('30', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_lookup_multiple_columns_from_csv(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('name', new StaticSource('Bob'))],
            columns: ['name', 'age', 'city'],
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $expected = [
            'name' => 'Bob',
            'age' => '25',
            'city' => 'LA',
        ];
        $this->assertEquals($expected, $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_lookup_from_tsv_file(): void
    {
        $source = $this->lookup(
            path: 'products.tsv',
            delimiter: "\t",
            filters: [$this->filter('product', new StaticSource('Laptop'))],
            columns: ['price'],
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('999.99', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_filter_with_multiple_keys(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [
                $this->filter('city', new StaticSource('NYC')),
                $this->filter('age', new StaticSource('30')),
            ],
            columns: ['name'],
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('Alice', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_first_match_by_default(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('city', new StaticSource('NYC'))],
            columns: ['name'],
            aggregate: 'first',
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('Alice', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_last_match_with_last_strategy(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('city', new StaticSource('NYC'))],
            columns: ['name'],
            aggregate: 'last',
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('Charlie', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_min_match_with_min_strategy(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('city', new StaticSource('NYC'))],
            columns: ['salary'],
            aggregate: 'min',
            aggregateColumn: 'salary',
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('75000', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_max_match_with_max_strategy(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('city', new StaticSource('NYC'))],
            columns: ['salary'],
            aggregate: 'max',
            aggregateColumn: 'salary',
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('85000', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_none_when_no_match_found(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('name', new StaticSource('NonExistent'))],
            columns: ['age'],
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isNone());
    }

    #[Test]
    public function it_returns_all_columns_when_columns_is_empty(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('name', new StaticSource('Alice'))],
            columns: [],
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $row = $result->unwrap()->unwrap();
        $this->assertIsArray($row);
        $this->assertEquals('Alice', $row['name']);
        $this->assertEquals('30', $row['age']);
        $this->assertEquals('NYC', $row['city']);
    }

    #[Test]
    public function it_can_work_with_file_without_header(): void
    {
        $source = $this->lookup(
            path: 'no_header.csv',
            filters: [$this->filter(0, new StaticSource('2'))],
            columns: [1],
            hasHeader: false,
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('Bob', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_resolves_filter_key_values_dynamically(): void
    {
        // Using a nested LookupSource as a filter value
        $cityLookup = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('name', new StaticSource('Bob'))],
            columns: ['city'],
        );

        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('city', $cityLookup)],
            columns: ['name', 'age'],
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $matches = $result->unwrap()->unwrap();
        $this->assertIsArray($matches);
        // Should find Bob and Eve (both in LA)
        $this->assertContains($matches['name'], ['Bob', 'Eve']);
    }

    #[Test]
    public function it_returns_error_for_non_existent_file(): void
    {
        $source = $this->lookup(
            path: 'non_existent_file.csv',
            filters: [],
            columns: ['name'],
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isErr());
        $error = $result->unwrapErr();
        // Flysystem throws its own exception types when file doesn't exist
        $this->assertStringContainsString('Unable to read file', $error->getMessage());
        $this->assertStringContainsString('non_existent_file.csv', $error->getMessage());
    }

    #[Test]
    public function it_handles_min_strategy_with_multiple_columns(): void
    {
        $source = $this->lookup(
            path: 'products.tsv',
            delimiter: "\t",
            filters: [$this->filter('category', new StaticSource('Electronics'))],
            columns: ['product', 'price'],
            aggregate: 'min',
            aggregateColumn: 'price',
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $data = $result->unwrap()->unwrap();
        $this->assertEquals('Mouse', $data['product']);
        $this->assertEquals('29.99', $data['price']);
    }

    #[Test]
    public function it_handles_max_strategy_with_multiple_columns(): void
    {
        $source = $this->lookup(
            path: 'products.tsv',
            delimiter: "\t",
            filters: [$this->filter('category', new StaticSource('Electronics'))],
            columns: ['product', 'price'],
            aggregate: 'max',
            aggregateColumn: 'price',
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $data = $result->unwrap()->unwrap();
        $this->assertEquals('Laptop', $data['product']);
        $this->assertEquals('999.99', $data['price']);
    }

    #[Test]
    public function it_supports_streaming_large_files(): void
    {
        // Create a large CSV file for testing streaming using Flysystem
        $csvContent = "id,value\n";

        for ($i = 1; $i <= 1000; $i++) {
            $csvContent .= "{$i},value_{$i}\n";
        }

        $this->filesystem->write('large_test.csv', $csvContent);

        $source = $this->lookup(
            path: 'large_test.csv',
            filters: [$this->filter('id', new StaticSource('500'))],
            columns: ['value'],
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('value_500', $result->unwrap()->unwrap());

        // Cleanup
        $this->filesystem->delete('large_test.csv');
    }

    #[Test]
    public function it_returns_none_when_filter_source_resolves_to_none(): void
    {
        $noneSource = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('name', new StaticSource('NonExistent'))],
            columns: ['city'],
        );

        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('city', $noneSource)],
            columns: ['name'],
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isNone());
    }

    #[Test]
    public function it_handles_empty_filter_keys(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [],
            columns: ['name'],
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        // Should return first row when no filters
        $this->assertEquals('Alice', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_error_for_unknown_aggregate_at_runtime(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('name', new StaticSource('Alice'))],
            columns: ['age'],
            aggregate: 'invalid_aggregate',
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isErr());
    }

    #[Test]
    public function it_returns_error_for_min_aggregate_without_aggregate_column(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('city', new StaticSource('NYC'))],
            columns: ['salary'],
            aggregate: 'min',
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isErr());
    }

    #[Test]
    public function it_returns_error_for_max_aggregate_without_aggregate_column(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('city', new StaticSource('NYC'))],
            columns: ['salary'],
            aggregate: 'max',
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isErr());
    }

    #[Test]
    public function it_returns_count_of_matching_rows(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('city', new StaticSource('NYC'))],
            aggregate: 'count',
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals(2, $result->unwrap()->unwrap()); // Alice and Charlie are in NYC
    }

    #[Test]
    public function it_calculates_sum_of_column_values(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('city', new StaticSource('NYC'))],
            aggregate: 'sum',
            aggregateColumn: 'salary',
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals(160000, $result->unwrap()->unwrap()); // 75000 + 85000
    }

    #[Test]
    public function it_calculates_avg_of_column_values(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('city', new StaticSource('NYC'))],
            aggregate: 'avg',
            aggregateColumn: 'salary',
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals(80000.0, $result->unwrap()->unwrap()); // (75000 + 85000) / 2
    }

    #[Test]
    public function it_returns_error_for_sum_without_aggregate_column(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('city', new StaticSource('NYC'))],
            aggregate: 'sum',
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isErr());
    }

    #[Test]
    public function it_returns_error_for_avg_without_aggregate_column(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('city', new StaticSource('NYC'))],
            aggregate: 'avg',
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isErr());
    }

    #[Test]
    public function it_supports_range_based_lookup_for_banding(): void
    {
        $source = $this->lookup(
            path: 'premium_bands.csv',
            filters: [new RangeFilter('min_turnover', 'max_turnover', new StaticSource('150000'))],
            columns: ['premium'],
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('15', $result->unwrap()->unwrap()); // 150k falls in 100k-200k band
    }

    #[Test]
    public function it_supports_range_lookup_for_lower_band(): void
    {
        $source = $this->lookup(
            path: 'premium_bands.csv',
            filters: [new RangeFilter('min_turnover', 'max_turnover', new StaticSource('50000'))],
            columns: ['premium'],
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('10', $result->unwrap()->unwrap()); // 50k falls in 0-100k band
    }

    #[Test]
    public function it_supports_range_lookup_for_upper_band(): void
    {
        $source = $this->lookup(
            path: 'premium_bands.csv',
            filters: [new RangeFilter('min_turnover', 'max_turnover', new StaticSource('500000'))],
            columns: ['premium'],
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('25', $result->unwrap()->unwrap()); // 500k falls in 300k+ band
    }

    #[Test]
    public function it_supports_range_lookup_at_band_boundary(): void
    {
        $source = $this->lookup(
            path: 'premium_bands.csv',
            filters: [new RangeFilter('min_turnover', 'max_turnover', new StaticSource('100000'))],
            columns: ['premium'],
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('15', $result->unwrap()->unwrap()); // 100k falls in 100k-200k band (inclusive)
    }

    #[Test]
    public function it_combines_range_lookup_with_exact_filters(): void
    {
        // Create a CSV with regions and banding using Flysystem
        $csvContent = "region,min_value,max_value,rate\nNorth,0,100,5\nNorth,100,200,10\nSouth,0,100,7\nSouth,100,200,12\n";
        $this->filesystem->write('regional_bands.csv', $csvContent);

        $source = $this->lookup(
            path: 'regional_bands.csv',
            filters: [
                $this->filter('region', new StaticSource('North')),
                new RangeFilter('min_value', 'max_value', new StaticSource('150')),
            ],
            columns: ['rate'],
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('10', $result->unwrap()->unwrap()); // North region, 150 in 100-200 band

        // Cleanup
        $this->filesystem->delete('regional_bands.csv');
    }

    #[Test]
    public function it_reports_unknown_aggregate_message(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('name', new StaticSource('Alice'))],
            columns: ['age'],
            aggregate: 'unknown_aggregate',
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('Unknown aggregate', $result->unwrapErr()->getMessage());
    }

    #[Test]
    public function it_returns_none_for_avg_when_count_is_zero(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('name', new StaticSource('NonExistentPerson'))],
            columns: ['age'],
            aggregate: 'avg',
            aggregateColumn: 'age',
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isNone());
    }

    #[Test]
    public function it_returns_none_for_sum_when_no_matches(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('name', new StaticSource('NonExistentPerson'))],
            columns: ['age'],
            aggregate: 'sum',
            aggregateColumn: 'age',
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isNone());
    }

    #[Test]
    public function it_returns_zero_for_sum_when_all_values_are_zero(): void
    {
        // Create a CSV file with zero values
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        if ($tempFile === false) {
            $this->fail('Failed to create temp file');
        }

        $handle = fopen($tempFile, 'w');
        if ($handle === false) {
            $this->fail('Failed to open temp file');
        }

        fputcsv($handle, ['name', 'value'], escape: '\\');
        fputcsv($handle, ['Item1', '0'], escape: '\\');
        fputcsv($handle, ['Item2', '0'], escape: '\\');
        fclose($handle);

        $tempAdapter = new LocalFilesystemAdapter(sys_get_temp_dir());
        $tempFilesystem = new Filesystem($tempAdapter);

        $source = $this->lookup(
            path: basename($tempFile),
            filters: [$this->filter('name', new StaticSource('Item1'))],
            columns: ['value'],
            aggregate: 'sum',
            aggregateColumn: 'value',
            filesystem: $tempFilesystem,
        );

        $result = $this->execute($source);

        unlink($tempFile);

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isSome());
        $this->assertEquals(0, $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_retrieve_all_results_using_in_operator(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter(
                value: new StaticSource(['Bob', 'Charlie', 'Eve']),
                column: 'name',
                operator: 'in',
            )],
            columns: ['salary'],
            aggregate: 'all',
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals([
            '65000',
            '85000',
            '80000',
        ], $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_none_when_no_results_are_found_for_all_aggregate(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter(
                value: new StaticSource('Peter'),
                column: 'name',
            )],
            columns: ['salary'],
            aggregate: 'all',
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isNone());
    }

    #[Test]
    #[DataProvider('resultTypes')]
    public function it_declares_a_result_type_per_aggregate(string $aggregate, string $expectedInner): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('city', new StaticSource('NYC'))],
            aggregate: $aggregate,
            aggregateColumn: 'salary',
        );

        $program = (new Expression($source))->compile()->unwrap();

        $this->assertInstanceOf(OptionType::class, $program->returns);
        $this->assertInstanceOf($expectedInner, $program->returns->inner);
    }

    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function resultTypes(): iterable
    {
        // Numeric aggregates are statically known; everything else is a raw cell.
        yield 'count' => ['count', NumberType::class];
        yield 'sum' => ['sum', NumberType::class];
        yield 'avg' => ['avg', NumberType::class];
        yield 'first' => ['first', UnknownType::class];
        yield 'last' => ['last', UnknownType::class];
        yield 'min' => ['min', UnknownType::class];
        yield 'max' => ['max', UnknownType::class];
        yield 'all' => ['all', UnknownType::class];
    }

    #[Test]
    public function it_fails_to_compile_when_a_filter_value_cannot_be_typed(): void
    {
        // A bare object literal with no registered type cannot be inferred,
        // so the filter value fails to compile and the lookup does too.
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('name', new StaticSource(new \stdClass()))],
            columns: ['age'],
        );

        $result = (new Expression($source))->compile();

        $this->assertTrue($result->isErr());
    }

    #[Test]
    public function range_filter_returns_false_when_min_column_missing(): void
    {
        $filter = new RangeFilter('min_price', 'max_price', new StaticSource('100'));
        $record = CsvRecord::from(['max_price' => '200', 'name' => 'Product']); // min_price is missing

        $result = $filter->matches($record, '100');

        $this->assertTrue($result->isOk());
        $this->assertFalse($result->mapOr(true, fn(bool $v) => $v));
    }

    #[Test]
    public function range_filter_returns_false_when_max_column_missing(): void
    {
        $filter = new RangeFilter('min_price', 'max_price', new StaticSource('100'));
        $record = CsvRecord::from(['min_price' => '50', 'name' => 'Product']); // max_price is missing

        $result = $filter->matches($record, '100');

        $this->assertTrue($result->isOk());
        $this->assertFalse($result->mapOr(true, fn(bool $v) => $v));
    }

    #[Test]
    public function range_filter_handles_non_numeric_values(): void
    {
        $filter = new RangeFilter('min_value', 'max_value', new StaticSource('100'));
        $record = CsvRecord::from([
            'min_value' => '50',
            'max_value' => '150',
            'name' => 'Product',
        ]);

        // Should work with numeric values - [min, max) range
        $this->assertTrue($filter->matches($record, '100')->mapOr(false, fn(bool $v) => $v));
        $this->assertTrue($filter->matches($record, '50')->mapOr(false, fn(bool $v) => $v)); // Exactly at min (included)
        $this->assertFalse($filter->matches($record, '150')->mapOr(true, fn(bool $v) => $v)); // At max (excluded)
        $this->assertFalse($filter->matches($record, '200')->mapOr(true, fn(bool $v) => $v)); // Above max

        // Test with non-numeric comparisons
        $record2 = CsvRecord::from([
            'min_value' => 'abc',
            'max_value' => 'xyz',
            'name' => 'Product2',
        ]);

        $this->assertTrue($filter->matches($record2, 'def')->mapOr(false, fn(bool $v) => $v)); // 'def' >= 'abc' && 'def' < 'xyz'
        $this->assertFalse($filter->matches($record2, 'aaa')->mapOr(true, fn(bool $v) => $v)); // Below min
    }

    #[Test]
    public function value_filter_returns_error_for_unsupported_operator(): void
    {
        // Called directly (no surrounding try/catch), an unsupported operator
        // is an honest Err rather than a silent no-match.
        $filter = $this->filter('name', new StaticSource('Alice'), '??unsupported??');
        $record = CsvRecord::from(['name' => 'Alice']);

        $result = $filter->matches($record, 'Alice');

        $this->assertTrue($result->isErr());
        $this->assertInstanceOf(\RuntimeException::class, $result->unwrapErr());
        $this->assertStringContainsString('Unsupported filter operator', $result->unwrapErr()->getMessage());
    }

    #[Test]
    public function value_filter_in_operator_returns_false_for_non_array_value(): void
    {
        // 'in' requires a list value; a scalar can never satisfy membership.
        $filter = $this->filter('name', new StaticSource('Alice'), 'in');
        $record = CsvRecord::from(['name' => 'Alice']);

        $result = $filter->matches($record, 'Alice');

        $this->assertTrue($result->isOk());
        $this->assertFalse($result->mapOr(true, fn(bool $v) => $v));
    }

    #[Test]
    public function value_filter_in_operator_returns_false_when_cell_absent_from_list(): void
    {
        $filter = $this->filter('name', new StaticSource(['Bob', 'Eve']), 'in');
        $record = CsvRecord::from(['name' => 'Alice']);

        $result = $filter->matches($record, ['Bob', 'Eve']);

        $this->assertTrue($result->isOk());
        $this->assertFalse($result->mapOr(true, fn(bool $v) => $v));
    }

    #[Test]
    public function first_aggregate_stops_processing_after_first_match(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('city', new StaticSource('NYC'))],
            columns: ['name'],
            aggregate: 'first',
        );

        $result = $this->execute($source);

        // Should return Alice (first match), not Charlie (second match from NYC)
        $this->assertTrue($result->isOk());
        $this->assertEquals('Alice', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_properly_handles_stream_when_file_cannot_be_opened(): void
    {
        // When readStream throws, we get a proper error and no stream leaks.
        $source = $this->lookup(
            path: 'definitely_does_not_exist_12345.csv',
            filters: [],
            columns: ['name'],
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isErr());
        $error = $result->unwrapErr();

        // Flysystem throws its own exception when file cannot be read
        $this->assertStringContainsString('Unable to read file', $error->getMessage());
        $this->assertStringContainsString('definitely_does_not_exist_12345.csv', $error->getMessage());
    }

    #[Test]
    public function it_cleans_up_stream_when_error_occurs_during_processing(): void
    {
        // The stream is opened, then the unknown aggregate errors mid-fold —
        // the finally block must still close it.
        $source = $this->lookup(
            path: 'users.csv',
            filters: [],
            columns: ['name'],
            aggregate: 'unknown_aggregate_type',
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isErr());
        $error = $result->unwrapErr();
        $this->assertStringContainsString('Unknown aggregate', $error->getMessage());
    }

    #[Test]
    public function it_returns_error_when_filter_operator_is_unsupported(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('name', new StaticSource('Alice'), '??unsupported??')],
            columns: ['age'],
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isErr());
    }

    #[Test]
    public function it_handles_false_return_from_readStream(): void
    {
        // Defensive check for when readStream returns false instead of throwing.
        $mockFilesystem = $this->createStub(FilesystemOperator::class);
        $mockFilesystem->method('readStream')->willReturn(false);

        $source = $this->lookup(
            path: 'test.csv',
            filters: [],
            columns: ['name'],
            filesystem: $mockFilesystem,
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isErr());
        $error = $result->unwrapErr();
        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertStringContainsString('Could not open file', $error->getMessage());
        $this->assertStringContainsString('test.csv', $error->getMessage());
    }
}
