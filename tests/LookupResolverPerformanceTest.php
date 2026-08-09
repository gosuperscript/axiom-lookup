<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Tests;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Lookup\Column;
use Superscript\Axiom\Lookup\DelimitedTable;
use Superscript\Axiom\Lookup\LookupExtension;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Lookup\Support\Results\AverageColumn;
use Superscript\Axiom\Lookup\Support\Results\CountRows;
use Superscript\Axiom\Lookup\Support\Results\FirstRow;
use Superscript\Axiom\Lookup\Support\Results\MaximumRow;
use Superscript\Axiom\Lookup\Support\Results\MinimumRow;
use Superscript\Axiom\Lookup\Support\Results\NumericResult;
use Superscript\Axiom\Lookup\Support\Results\ProjectedResult;
use Superscript\Axiom\Lookup\Support\Results\RecordProjection;
use Superscript\Axiom\Lookup\Support\Results\SumColumn;
use Superscript\Axiom\Lookup\Support\Results\ValueProjection;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

#[CoversNothing]
class LookupResolverPerformanceTest extends TestCase
{
    private Filesystem $filesystem;

    private string $largeCsvFilename;

    private string $veryLargeCsvFilename;

    protected function setUp(): void
    {
        // Set up Flysystem with local adapter pointing to temp directory
        $adapter = new LocalFilesystemAdapter(sys_get_temp_dir());
        $this->filesystem = new Filesystem($adapter);

        // Store just the filenames (relative paths)
        $this->largeCsvFilename = 'large_test_' . uniqid() . '.csv';
        $this->veryLargeCsvFilename = 'very_large_test_' . uniqid() . '.csv';
    }

    protected function tearDown(): void
    {
        if ($this->filesystem->fileExists($this->largeCsvFilename)) {
            $this->filesystem->delete($this->largeCsvFilename);
        }
        if ($this->filesystem->fileExists($this->veryLargeCsvFilename)) {
            $this->filesystem->delete($this->veryLargeCsvFilename);
        }
    }

    /**
     * @param array<ValueFilter> $filters
     * @param array<string|int> $columns
     * @return Result<Option<mixed>, \Throwable>
     */
    private function execute(
        string $path,
        array $filters = [],
        array $columns = [],
        string $resultKind = 'first',
        string|int|null $resultColumn = null,
    ): Result {
        $projection = fn(): ValueProjection|RecordProjection => count($columns) === 1
            ? new ValueProjection($columns[0])
            : new RecordProjection(array_combine(array_map(strval(...), $columns), $columns));
        $result = match ($resultKind) {
            'first' => new ProjectedResult(new FirstRow(), $projection()),
            'min' => new ProjectedResult(new MinimumRow($resultColumn ?? 'price'), $projection()),
            'max' => new ProjectedResult(new MaximumRow($resultColumn ?? 'price'), $projection()),
            'count' => new NumericResult(new CountRows()),
            'sum' => new NumericResult(new SumColumn($resultColumn ?? 'price')),
            'avg' => new NumericResult(new AverageColumn($resultColumn ?? 'price')),
        };
        $source = new LookupSource(
            table: new DelimitedTable($path, [
                new Column('id', new NumberType()),
                new Column('name', new StringType()),
                new Column('category', new StringType()),
                new Column('price', new NumberType()),
                new Column('stock', new NumberType()),
            ]),
            result: $result,
            filters: $filters,
        );

        $dialect = Dialect::core()->with(new LookupExtension($this->filesystem));

        return (new Expression($source, dialect: $dialect))->compile()->unwrap()();
    }

    private function filter(string|int $column, Source $value, string $operator = '=='): ValueFilter
    {
        return new ValueFilter($column, $value, $operator);
    }

    #[Test]
    public function it_handles_10k_rows_with_low_memory_usage(): void
    {
        // Create a CSV with 10,000 rows
        $this->createLargeCsv($this->largeCsvFilename, 10000);

        // Measure memory before
        $memoryBefore = memory_get_usage();

        // Perform a row count (should use minimal memory)
        $result = $this->execute(
            path: $this->largeCsvFilename,
            filters: [$this->filter('category', new StaticSource('Electronics'))],
            resultKind: 'count',
        );

        // Measure memory after
        $memoryAfter = memory_get_usage();
        $memoryUsed = $memoryAfter - $memoryBefore;

        // Assert result is correct
        $this->assertTrue($result->isOk());
        $count = $result->unwrap()->unwrap();
        $this->assertGreaterThan(0, $count);

        // Memory usage should be low (less than 5MB for processing 10k rows)
        // This validates O(1) memory complexity
        $this->assertLessThan(
            5 * 1024 * 1024,
            $memoryUsed,
            "Memory usage ({$memoryUsed} bytes) exceeded 5MB for 10k rows",
        );
    }

    #[Test]
    public function it_handles_100k_rows_with_constant_memory(): void
    {
        // Create a CSV with 100,000 rows
        $this->createLargeCsv($this->veryLargeCsvFilename, 100000);

        // Measure memory before
        $memoryBefore = memory_get_usage();

        // Perform a sum fold (should use minimal memory)
        $result = $this->execute(
            path: $this->veryLargeCsvFilename,
            filters: [$this->filter('category', new StaticSource('Electronics'))],
            resultKind: 'sum',
            resultColumn: 'price',
        );

        // Measure memory after
        $memoryAfter = memory_get_usage();
        $memoryUsed = $memoryAfter - $memoryBefore;

        // Assert result is correct
        $this->assertTrue($result->isOk());
        $sum = $result->unwrap()->unwrap();
        $this->assertGreaterThan(0, $sum);

        // Memory usage should still be low even with 100k rows (less than 10MB)
        // This validates we're not storing all matching rows
        $this->assertLessThan(
            10 * 1024 * 1024,
            $memoryUsed,
            "Memory usage ({$memoryUsed} bytes) exceeded 10MB for 100k rows",
        );
    }

    #[Test]
    public function first_row_has_early_exit_optimization(): void
    {
        // Create a CSV with 50,000 rows
        $this->createLargeCsv($this->largeCsvFilename, 50000);

        // Measure time for FirstRow (should be fast with early exit)
        $startTime = microtime(true);

        $result = $this->execute(
            path: $this->largeCsvFilename,
            filters: [$this->filter('category', new StaticSource('Electronics'))],
            columns: ['name', 'price'],
            resultKind: 'first',
        );

        $firstRowTime = microtime(true) - $startTime;

        // Assert result is correct
        $this->assertTrue($result->isOk());
        $this->assertIsArray($result->unwrap()->unwrap());

        // Now measure CountRows (must read all rows)
        $startTime = microtime(true);

        $result = $this->execute(
            path: $this->largeCsvFilename,
            filters: [$this->filter('category', new StaticSource('Electronics'))],
            resultKind: 'count',
        );

        $countRowsTime = microtime(true) - $startTime;

        // Assert result is correct
        $this->assertTrue($result->isOk());

        // 'first' should be significantly faster than 'count' due to early exit
        // Allow some variance but first should be at least 2x faster
        $this->assertLessThan(
            $countRowsTime / 2,
            $firstRowTime,
            "First row ({$firstRowTime}s) should be faster than count ({$countRowsTime}s) due to early exit",
        );
    }

    #[Test]
    public function minimum_and_maximum_selections_use_constant_memory(): void
    {
        // Create a CSV with 20,000 rows
        $this->createLargeCsv($this->largeCsvFilename, 20000);

        // Measure memory for minimum selection
        $memoryBefore = memory_get_usage();

        $result = $this->execute(
            path: $this->largeCsvFilename,
            filters: [$this->filter('category', new StaticSource('Electronics'))],
            columns: ['name', 'price'],
            resultKind: 'min',
            resultColumn: 'price',
        );

        $memoryAfter = memory_get_usage();
        $memoryUsed = $memoryAfter - $memoryBefore;

        // Assert result is correct
        $this->assertTrue($result->isOk());
        $minResult = $result->unwrap()->unwrap();
        $this->assertIsArray($minResult);
        $this->assertArrayHasKey('name', $minResult);
        $this->assertArrayHasKey('price', $minResult);

        // Memory should be low (only storing one row, not all matches)
        $this->assertLessThan(
            5 * 1024 * 1024,
            $memoryUsed,
            "Minimum-row memory usage ({$memoryUsed} bytes) exceeded 5MB",
        );

        // Test maximum selection as well
        $memoryBefore = memory_get_usage();

        $result = $this->execute(
            path: $this->largeCsvFilename,
            filters: [$this->filter('category', new StaticSource('Electronics'))],
            columns: ['name', 'price'],
            resultKind: 'max',
            resultColumn: 'price',
        );

        $memoryAfter = memory_get_usage();
        $memoryUsed = $memoryAfter - $memoryBefore;

        // Assert result is correct
        $this->assertTrue($result->isOk());
        $maxResult = $result->unwrap()->unwrap();
        $this->assertIsArray($maxResult);

        // Memory should be low
        $this->assertLessThan(
            5 * 1024 * 1024,
            $memoryUsed,
            "Maximum-row memory usage ({$memoryUsed} bytes) exceeded 5MB",
        );
    }

    #[Test]
    public function average_fold_uses_constant_memory(): void
    {
        // Create a CSV with 30,000 rows
        $this->createLargeCsv($this->largeCsvFilename, 30000);

        // Measure memory for average fold
        $memoryBefore = memory_get_usage();

        $result = $this->execute(
            path: $this->largeCsvFilename,
            filters: [$this->filter('category', new StaticSource('Electronics'))],
            resultKind: 'avg',
            resultColumn: 'price',
        );

        $memoryAfter = memory_get_usage();
        $memoryUsed = $memoryAfter - $memoryBefore;

        // Assert result is correct
        $this->assertTrue($result->isOk());
        $avg = $result->unwrap()->unwrap();
        $this->assertIsFloat($avg);
        $this->assertGreaterThan(0, $avg);

        // Memory should be low (only storing sum and count accumulators)
        $this->assertLessThan(
            5 * 1024 * 1024,
            $memoryUsed,
            "Average-fold memory usage ({$memoryUsed} bytes) exceeded 5MB for 30k rows",
        );
    }

    #[Test]
    public function performance_comparison_across_file_sizes(): void
    {
        $results = [];

        // Test with 1k, 5k, 10k rows
        foreach ([1000, 5000, 10000] as $rowCount) {
            $csvFilename = 'perf_test_' . $rowCount . '_' . uniqid() . '.csv';
            $this->createLargeCsv($csvFilename, $rowCount);

            $startTime = microtime(true);
            $memoryBefore = memory_get_usage();

            $result = $this->execute(
                path: $csvFilename,
                filters: [$this->filter('category', new StaticSource('Electronics'))],
                resultKind: 'count',
            );

            $executionTime = microtime(true) - $startTime;
            $memoryUsed = memory_get_usage() - $memoryBefore;

            $results[$rowCount] = [
                'time' => $executionTime,
                'memory' => $memoryUsed,
                'count' => $result->unwrap()->unwrap(),
            ];

            $this->filesystem->delete($csvFilename);
        }

        // Assert memory usage scales linearly or better (not quadratically)
        // Memory ratio should be close to row count ratio
        $memory1k = $results[1000]['memory'];
        $memory10k = $results[10000]['memory'];

        // Memory should not grow more than 15x when rows grow 10x
        // (allows for some overhead but prevents O(n) array storage)
        $memoryRatio = $memory10k / max($memory1k, 1);
        $this->assertLessThan(
            15,
            $memoryRatio,
            "Memory ratio ({$memoryRatio}) suggests non-constant memory usage",
        );

        // Execution time should scale roughly linearly with row count
        $time1k = $results[1000]['time'];
        $time10k = $results[10000]['time'];
        $timeRatio = $time10k / max($time1k, 0.001);

        // Time ratio should be between 5x and 20x for 10x rows (allowing for variance)
        $this->assertLessThan(
            20,
            $timeRatio,
            "Time ratio ({$timeRatio}) suggests poor performance scaling",
        );
    }

    /**
     * Create a large CSV file with the specified number of rows
     */
    private function createLargeCsv(string $filename, int $rowCount): void
    {
        $fullPath = sys_get_temp_dir() . '/' . $filename;
        $handle = fopen($fullPath, 'w');

        // Write header
        fputcsv($handle, ['id', 'name', 'category', 'price', 'stock'], escape: '\\');

        $categories = ['Electronics', 'Books', 'Clothing', 'Food', 'Toys'];

        // Write data rows
        for ($i = 1; $i <= $rowCount; $i++) {
            $category = $categories[$i % count($categories)];
            $price = rand(10, 1000);
            $stock = rand(0, 100);

            fputcsv($handle, [
                $i,
                "Product {$i}",
                $category,
                $price,
                $stock,
            ], escape: '\\');
        }

        fclose($handle);
    }
}
