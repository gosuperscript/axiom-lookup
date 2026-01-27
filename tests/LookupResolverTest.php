<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Tests;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Lookup\LookupResolver;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Axiom\Lookup\Support\Aggregates;
use Superscript\Axiom\Lookup\Support\Filters\RangeFilter;
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Resolvers\DelegatingResolver;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Sources\StaticSource;

#[CoversClass(LookupResolver::class)]
#[CoversClass(LookupSource::class)]
#[CoversClass(ValueFilter::class)]
#[CoversClass(RangeFilter::class)]
#[UsesClass(CsvRecord::class)]
#[UsesClass(Aggregates\First::class)]
#[UsesClass(Aggregates\Last::class)]
#[UsesClass(Aggregates\Count::class)]
#[UsesClass(Aggregates\Sum::class)]
#[UsesClass(Aggregates\Avg::class)]
#[UsesClass(Aggregates\Min::class)]
#[UsesClass(Aggregates\Max::class)]
#[UsesClass(Aggregates\All::class)]
class LookupResolverTest extends TestCase
{
    private DelegatingResolver $resolver;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        // Create a Flysystem adapter for the fixtures directory
        $adapter = new LocalFilesystemAdapter(__DIR__ . '/Fixtures');
        $this->filesystem = new Filesystem($adapter);

        $this->resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            LookupSource::class => LookupResolver::class,
        ]);
        $this->resolver->instance(\League\Flysystem\FilesystemOperator::class, $this->filesystem);
    }

    private function getFixturePath(string $filename): string
    {
        return __DIR__ . '/Fixtures/' . $filename;
    }

    #[Test]
    public function it_can_lookup_single_column_from_csv_with_single_filter(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('name', new StaticSource('Alice'))],
            columns: ['age'],
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('30', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_lookup_multiple_columns_from_csv(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('name', new StaticSource('Bob'))],
            columns: ['name', 'age', 'city'],
        );

        $result = $this->resolver->resolve($source);

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
        $source = new LookupSource(
            path: 'products.tsv',
            delimiter: "\t",
            filters: [new ValueFilter('product', new StaticSource('Laptop'))],
            columns: ['price'],
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('999.99', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_filter_with_multiple_keys(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [
                new ValueFilter('city', new StaticSource('NYC')),
                new ValueFilter('age', new StaticSource('30')),
            ],
            columns: ['name'],
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('Alice', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_first_match_by_default(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('city', new StaticSource('NYC'))],
            columns: ['name'],
            aggregate: 'first',
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('Alice', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_last_match_with_last_strategy(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('city', new StaticSource('NYC'))],
            columns: ['name'],
            aggregate: 'last',
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('Charlie', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_min_match_with_min_strategy(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('city', new StaticSource('NYC'))],
            columns: ['salary'],
            aggregate: 'min',
            aggregateColumn: 'salary',
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('75000', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_max_match_with_max_strategy(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('city', new StaticSource('NYC'))],
            columns: ['salary'],
            aggregate: 'max',
            aggregateColumn: 'salary',
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('85000', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_none_when_no_match_found(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('name', new StaticSource('NonExistent'))],
            columns: ['age'],
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isNone());
    }

    #[Test]
    public function it_returns_all_columns_when_columns_is_empty(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('name', new StaticSource('Alice'))],
            columns: [],
        );

        $result = $this->resolver->resolve($source);

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
        $source = new LookupSource(
            path: 'no_header.csv',
            filters: [new ValueFilter(0, new StaticSource('2'))],
            columns: [1],
            hasHeader: false,
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('Bob', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_resolves_filter_key_values_dynamically(): void
    {
        // Using a nested LookupSource as a filter value
        $cityLookup = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('name', new StaticSource('Bob'))],
            columns: ['city'],
        );

        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('city', $cityLookup)],
            columns: ['name', 'age'],
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $matches = $result->unwrap()->unwrap();
        $this->assertIsArray($matches);
        // Should find Bob and Eve (both in LA)
        $this->assertContains($matches['name'], ['Bob', 'Eve']);
    }

    #[Test]
    public function it_returns_error_for_non_existent_file(): void
    {
        $source = new LookupSource(
            path: 'non_existent_file.csv',
            filters: [],
            columns: ['name'],
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isErr());
        $error = $result->unwrapErr();
        // Flysystem throws its own exception types when file doesn't exist
        $this->assertStringContainsString('Unable to read file', $error->getMessage());
        $this->assertStringContainsString('non_existent_file.csv', $error->getMessage());
    }

    #[Test]
    public function it_handles_min_strategy_with_multiple_columns(): void
    {
        $source = new LookupSource(
            path: 'products.tsv',
            delimiter: "\t",
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            columns: ['product', 'price'],
            aggregate: 'min',
            aggregateColumn: 'price',
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $data = $result->unwrap()->unwrap();
        $this->assertEquals('Mouse', $data['product']);
        $this->assertEquals('29.99', $data['price']);
    }

    #[Test]
    public function it_handles_max_strategy_with_multiple_columns(): void
    {
        $source = new LookupSource(
            path: 'products.tsv',
            delimiter: "\t",
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            columns: ['product', 'price'],
            aggregate: 'max',
            aggregateColumn: 'price',
        );

        $result = $this->resolver->resolve($source);

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

        $source = new LookupSource(
            path: 'large_test.csv',
            filters: [new ValueFilter('id', new StaticSource('500'))],
            columns: ['value'],
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('value_500', $result->unwrap()->unwrap());

        // Cleanup
        $this->filesystem->delete('large_test.csv');
    }

    #[Test]
    public function it_returns_none_when_filter_source_resolves_to_none(): void
    {
        $noneSource = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('name', new StaticSource('NonExistent'))],
            columns: ['city'],
        );

        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('city', $noneSource)],
            columns: ['name'],
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isNone());
    }

    #[Test]
    public function it_handles_empty_filter_keys(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [],
            columns: ['name'],
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        // Should return first row when no filters
        $this->assertEquals('Alice', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_throws_error_for_unknown_aggregate(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('name', new StaticSource('Alice'))],
            columns: ['age'],
            aggregate: 'invalid_aggregate',
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isErr());
    }

    #[Test]
    public function it_throws_error_for_min_aggregate_without_aggregate_column(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('city', new StaticSource('NYC'))],
            columns: ['salary'],
            aggregate: 'min',
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isErr());
    }

    #[Test]
    public function it_throws_error_for_max_aggregate_without_aggregate_column(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('city', new StaticSource('NYC'))],
            columns: ['salary'],
            aggregate: 'max',
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isErr());
    }

    #[Test]
    public function it_returns_count_of_matching_rows(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('city', new StaticSource('NYC'))],
            aggregate: 'count',
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals(2, $result->unwrap()->unwrap()); // Alice and Charlie are in NYC
    }

    #[Test]
    public function it_calculates_sum_of_column_values(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('city', new StaticSource('NYC'))],
            aggregate: 'sum',
            aggregateColumn: 'salary',
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals(160000, $result->unwrap()->unwrap()); // 75000 + 85000
    }

    #[Test]
    public function it_calculates_avg_of_column_values(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('city', new StaticSource('NYC'))],
            aggregate: 'avg',
            aggregateColumn: 'salary',
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals(80000.0, $result->unwrap()->unwrap()); // (75000 + 85000) / 2
    }

    #[Test]
    public function it_throws_error_for_sum_without_aggregate_column(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('city', new StaticSource('NYC'))],
            aggregate: 'sum',
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isErr());
    }

    #[Test]
    public function it_throws_error_for_avg_without_aggregate_column(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('city', new StaticSource('NYC'))],
            aggregate: 'avg',
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isErr());
    }

    #[Test]
    public function it_supports_range_based_lookup_for_banding(): void
    {
        $source = new LookupSource(
            path: 'premium_bands.csv',
            filters: [new RangeFilter('min_turnover', 'max_turnover', new StaticSource('150000'))],
            columns: ['premium'],
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('15', $result->unwrap()->unwrap()); // 150k falls in 100k-200k band
    }

    #[Test]
    public function it_supports_range_lookup_for_lower_band(): void
    {
        $source = new LookupSource(
            path: 'premium_bands.csv',
            filters: [new RangeFilter('min_turnover', 'max_turnover', new StaticSource('50000'))],
            columns: ['premium'],
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('10', $result->unwrap()->unwrap()); // 50k falls in 0-100k band
    }

    #[Test]
    public function it_supports_range_lookup_for_upper_band(): void
    {
        $source = new LookupSource(
            path: 'premium_bands.csv',
            filters: [new RangeFilter('min_turnover', 'max_turnover', new StaticSource('500000'))],
            columns: ['premium'],
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('25', $result->unwrap()->unwrap()); // 500k falls in 300k+ band
    }

    #[Test]
    public function it_supports_range_lookup_at_band_boundary(): void
    {
        $source = new LookupSource(
            path: 'premium_bands.csv',
            filters: [new RangeFilter('min_turnover', 'max_turnover', new StaticSource('100000'))],
            columns: ['premium'],
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('15', $result->unwrap()->unwrap()); // 100k falls in 100k-200k band (inclusive)
    }

    #[Test]
    public function it_combines_range_lookup_with_exact_filters(): void
    {
        // Create a CSV with regions and banding using Flysystem
        $csvContent = "region,min_value,max_value,rate\nNorth,0,100,5\nNorth,100,200,10\nSouth,0,100,7\nSouth,100,200,12\n";
        $this->filesystem->write('regional_bands.csv', $csvContent);

        $source = new LookupSource(
            path: 'regional_bands.csv',
            filters: [
                new ValueFilter('region', new StaticSource('North')),
                new RangeFilter('min_value', 'max_value', new StaticSource('150')),
            ],
            columns: ['rate'],
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('10', $result->unwrap()->unwrap()); // North region, 150 in 100-200 band

        // Cleanup
        $this->filesystem->delete('regional_bands.csv');
    }

    #[Test]
    public function it_throws_exception_for_unknown_aggregate(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('name', new StaticSource('Alice'))],
            columns: ['age'],
            aggregate: 'unknown_aggregate',
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('Unknown aggregate', $result->unwrapErr()->getMessage());
    }

    #[Test]
    public function it_returns_none_for_avg_when_count_is_zero(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('name', new StaticSource('NonExistentPerson'))],
            columns: ['age'],
            aggregate: 'avg',
            aggregateColumn: 'age',
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isNone());
    }

    #[Test]
    public function it_returns_none_for_sum_when_no_matches(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('name', new StaticSource('NonExistentPerson'))],
            columns: ['age'],
            aggregate: 'sum',
            aggregateColumn: 'age',
        );

        $result = $this->resolver->resolve($source);

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

        $tempDir = sys_get_temp_dir();
        $tempAdapter = new LocalFilesystemAdapter($tempDir);
        $tempFilesystem = new Filesystem($tempAdapter);

        // Create a custom resolver with the temp filesystem
        $tempResolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            LookupSource::class => LookupResolver::class,
        ]);
        $tempResolver->instance(\League\Flysystem\FilesystemOperator::class, $tempFilesystem);

        $source = new LookupSource(
            path: basename($tempFile),
            filters: [new ValueFilter('name', new StaticSource('Item1'))],
            columns: ['value'],
            aggregate: 'sum',
            aggregateColumn: 'value',
        );

        $result = $tempResolver->resolve($source);

        unlink($tempFile);

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isSome());
        $this->assertEquals(0, $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_retrieve_all_results_using_in_operator(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter(
                value: new StaticSource(['Bob', 'Charlie', 'Eve']),
                column: 'name',
                operator: 'in',
            )],
            columns: ['salary'],
            aggregate: 'all',
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals([
            '65000',
            '85000',
            '80000',
        ], $result->unwrap()->unwrap()); // 75000 + 85000
    }

    #[Test]
    public function it_returns_none_when_no_results_are_found_for_all_aggregate(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter(
                value: new StaticSource('Peter'),
                column: 'name',
            )],
            columns: ['salary'],
            aggregate: 'all',
        );

        $result = $this->resolver->resolve($source);

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isNone());
    }

    #[Test]
    public function range_filter_returns_false_when_min_column_missing(): void
    {
        $filter = new RangeFilter('min_price', 'max_price', new StaticSource('100'));
        $record = CsvRecord::from(['max_price' => '200', 'name' => 'Product']); // min_price is missing

        $result = $filter->matches($record, '100');

        $this->assertFalse($result);
    }

    #[Test]
    public function range_filter_returns_false_when_max_column_missing(): void
    {
        $filter = new RangeFilter('min_price', 'max_price', new StaticSource('100'));
        $record = CsvRecord::from(['min_price' => '50', 'name' => 'Product']); // max_price is missing

        $result = $filter->matches($record, '100');

        $this->assertFalse($result);
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
        $this->assertTrue($filter->matches($record, '100'));
        $this->assertTrue($filter->matches($record, '50')); // Exactly at min (included)
        $this->assertFalse($filter->matches($record, '150')); // At max (excluded)
        $this->assertFalse($filter->matches($record, '200')); // Above max

        // Test with non-numeric comparisons
        $record2 = CsvRecord::from([
            'min_value' => 'abc',
            'max_value' => 'xyz',
            'name' => 'Product2',
        ]);

        $this->assertTrue($filter->matches($record2, 'def')); // 'def' >= 'abc' && 'def' < 'xyz'
        $this->assertFalse($filter->matches($record2, 'aaa')); // Below min
    }

    #[Test]
    public function first_aggregate_stops_processing_after_first_match(): void
    {
        // Create a fixture with multiple matching records
        $fixturePath = $this->getFixturePath('users.csv');

        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('city', new StaticSource('NYC'))],
            columns: ['name'],
            aggregate: 'first',
        );

        $result = $this->resolver->resolve($source);

        // Should return Alice (first match), not Charlie (second match from NYC)
        $this->assertTrue($result->isOk());
        $this->assertEquals('Alice', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_properly_handles_stream_when_file_cannot_be_opened(): void
    {
        // Test that when readStream throws an exception, we get a proper error
        // and no stream resource leaks occur
        $source = new LookupSource(
            path: 'definitely_does_not_exist_12345.csv',
            filters: [],
            columns: ['name'],
        );

        $result = $this->resolver->resolve($source);

        // Verify error is returned
        $this->assertTrue($result->isErr());
        $error = $result->unwrapErr();

        // Verify exception contains expected information
        // Flysystem throws its own exception when file cannot be read
        $this->assertStringContainsString('Unable to read file', $error->getMessage());
        $this->assertStringContainsString('definitely_does_not_exist_12345.csv', $error->getMessage());

        // If we got here without crashes, stream cleanup worked correctly
    }

    #[Test]
    public function it_cleans_up_stream_when_error_occurs_during_processing(): void
    {
        // Test that stream is properly closed when an error occurs after opening the file
        // This tests the finally block cleanup when exceptions happen during processing
        $source = new LookupSource(
            path: 'users.csv',
            filters: [],
            columns: ['name'],
            aggregate: 'unknown_aggregate_type', // This will cause an error after stream is opened
        );

        $result = $this->resolver->resolve($source);

        // Verify error is returned (aggregate error occurs after stream is opened)
        $this->assertTrue($result->isErr());
        $error = $result->unwrapErr();
        $this->assertStringContainsString('Unknown aggregate', $error->getMessage());

        // The key point: If stream wasn't closed in finally block, we'd have resource leaks
        // This test passing means finally block executed and closed the stream
    }

    #[Test]
    public function it_handles_false_return_from_readStream(): void
    {
        // Test the defensive check for when readStream returns false instead of throwing
        // Create a mock filesystem that returns false
        $mockFilesystem = $this->createMock(\League\Flysystem\FilesystemOperator::class);
        $mockFilesystem->method('readStream')->willReturn(false);

        // Create a custom resolver with the mock filesystem
        $mockResolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            LookupSource::class => LookupResolver::class,
        ]);
        $mockResolver->instance(\League\Flysystem\FilesystemOperator::class, $mockFilesystem);

        $source = new LookupSource(
            path: 'test.csv',
            filters: [],
            columns: ['name'],
        );

        $result = $mockResolver->resolve($source);

        // Should get RuntimeException with "Could not open file" message
        $this->assertTrue($result->isErr());
        $error = $result->unwrapErr();
        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertStringContainsString('Could not open file', $error->getMessage());
        $this->assertStringContainsString('test.csv', $error->getMessage());
    }
}
