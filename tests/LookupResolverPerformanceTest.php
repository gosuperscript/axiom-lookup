<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Lookup\LookupResolver;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Resolvers\DelegatingResolver;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Sources\StaticSource;

#[CoversNothing]
class LookupResolverPerformanceTest extends TestCase
{
    private DelegatingResolver $resolver;
    private string $largeCsvPath;
    private string $veryLargeCsvPath;

    protected function setUp(): void
    {
        $this->resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            LookupSource::class => LookupResolver::class,
        ]);
        
        // Create test CSVs in tmp directory
        $this->largeCsvPath = sys_get_temp_dir() . '/large_test_' . uniqid() . '.csv';
        $this->veryLargeCsvPath = sys_get_temp_dir() . '/very_large_test_' . uniqid() . '.csv';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->largeCsvPath)) {
            unlink($this->largeCsvPath);
        }
        if (file_exists($this->veryLargeCsvPath)) {
            unlink($this->veryLargeCsvPath);
        }
    }

    #[Test]
    public function it_handles_10k_rows_with_low_memory_usage(): void
    {
        // Create a CSV with 10,000 rows
        $this->createLargeCsv($this->largeCsvPath, 10000);
        
        // Measure memory before
        $memoryBefore = memory_get_usage();
        
        // Perform a count aggregate (should use minimal memory)
        $source = new LookupSource(
            filePath: $this->largeCsvPath,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            aggregate: 'count',
        );
        
        $result = $this->resolver->resolve($source);
        
        // Measure memory after
        $memoryAfter = memory_get_usage();
        $memoryUsed = $memoryAfter - $memoryBefore;
        
        // Assert result is correct
        $this->assertTrue($result->isOk());
        $count = $result->unwrap()->unwrap();
        $this->assertGreaterThan(0, $count);
        
        // Memory usage should be low (less than 5MB for processing 10k rows)
        // This validates O(1) memory complexity
        $this->assertLessThan(5 * 1024 * 1024, $memoryUsed, 
            "Memory usage ({$memoryUsed} bytes) exceeded 5MB for 10k rows");
    }

    #[Test]
    public function it_handles_100k_rows_with_constant_memory(): void
    {
        // Create a CSV with 100,000 rows
        $this->createLargeCsv($this->veryLargeCsvPath, 100000);
        
        // Measure memory before
        $memoryBefore = memory_get_usage();
        
        // Perform a sum aggregate (should use minimal memory)
        $source = new LookupSource(
            filePath: $this->veryLargeCsvPath,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            aggregate: 'sum',
            aggregateColumn: 'price',
        );
        
        $result = $this->resolver->resolve($source);
        
        // Measure memory after
        $memoryAfter = memory_get_usage();
        $memoryUsed = $memoryAfter - $memoryBefore;
        
        // Assert result is correct
        $this->assertTrue($result->isOk());
        $sum = $result->unwrap()->unwrap();
        $this->assertGreaterThan(0, $sum);
        
        // Memory usage should still be low even with 100k rows (less than 10MB)
        // This validates we're not storing all matching rows
        $this->assertLessThan(10 * 1024 * 1024, $memoryUsed,
            "Memory usage ({$memoryUsed} bytes) exceeded 10MB for 100k rows");
    }

    #[Test]
    public function first_aggregate_has_early_exit_optimization(): void
    {
        // Create a CSV with 50,000 rows
        $this->createLargeCsv($this->largeCsvPath, 50000);
        
        // Measure time for 'first' aggregate (should be fast with early exit)
        $startTime = microtime(true);
        
        $source = new LookupSource(
            filePath: $this->largeCsvPath,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            columns: ['name', 'price'],
            aggregate: 'first',
        );
        
        $result = $this->resolver->resolve($source);
        
        $firstAggregateTime = microtime(true) - $startTime;
        
        // Assert result is correct
        $this->assertTrue($result->isOk());
        $this->assertIsArray($result->unwrap()->unwrap());
        
        // Now measure time for 'count' aggregate (must read all rows)
        $startTime = microtime(true);
        
        $source = new LookupSource(
            filePath: $this->largeCsvPath,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            aggregate: 'count',
        );
        
        $result = $this->resolver->resolve($source);
        
        $countAggregateTime = microtime(true) - $startTime;
        
        // Assert result is correct
        $this->assertTrue($result->isOk());
        
        // 'first' should be significantly faster than 'count' due to early exit
        // Allow some variance but first should be at least 2x faster
        $this->assertLessThan($countAggregateTime / 2, $firstAggregateTime,
            "First aggregate ({$firstAggregateTime}s) should be faster than count ({$countAggregateTime}s) due to early exit");
    }

    #[Test]
    public function min_max_aggregates_use_constant_memory(): void
    {
        // Create a CSV with 20,000 rows
        $this->createLargeCsv($this->largeCsvPath, 20000);
        
        // Measure memory for min aggregate
        $memoryBefore = memory_get_usage();
        
        $source = new LookupSource(
            filePath: $this->largeCsvPath,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            columns: ['name', 'price'],
            aggregate: 'min',
            aggregateColumn: 'price',
        );
        
        $result = $this->resolver->resolve($source);
        
        $memoryAfter = memory_get_usage();
        $memoryUsed = $memoryAfter - $memoryBefore;
        
        // Assert result is correct
        $this->assertTrue($result->isOk());
        $minResult = $result->unwrap()->unwrap();
        $this->assertIsArray($minResult);
        $this->assertArrayHasKey('name', $minResult);
        $this->assertArrayHasKey('price', $minResult);
        
        // Memory should be low (only storing one row, not all matches)
        $this->assertLessThan(5 * 1024 * 1024, $memoryUsed,
            "Min aggregate memory usage ({$memoryUsed} bytes) exceeded 5MB");
        
        // Test max aggregate as well
        $memoryBefore = memory_get_usage();
        
        $source = new LookupSource(
            filePath: $this->largeCsvPath,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            columns: ['name', 'price'],
            aggregate: 'max',
            aggregateColumn: 'price',
        );
        
        $result = $this->resolver->resolve($source);
        
        $memoryAfter = memory_get_usage();
        $memoryUsed = $memoryAfter - $memoryBefore;
        
        // Assert result is correct
        $this->assertTrue($result->isOk());
        $maxResult = $result->unwrap()->unwrap();
        $this->assertIsArray($maxResult);
        
        // Memory should be low
        $this->assertLessThan(5 * 1024 * 1024, $memoryUsed,
            "Max aggregate memory usage ({$memoryUsed} bytes) exceeded 5MB");
    }

    #[Test]
    public function avg_aggregate_uses_constant_memory(): void
    {
        // Create a CSV with 30,000 rows
        $this->createLargeCsv($this->largeCsvPath, 30000);
        
        // Measure memory for avg aggregate
        $memoryBefore = memory_get_usage();
        
        $source = new LookupSource(
            filePath: $this->largeCsvPath,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            aggregate: 'avg',
            aggregateColumn: 'price',
        );
        
        $result = $this->resolver->resolve($source);
        
        $memoryAfter = memory_get_usage();
        $memoryUsed = $memoryAfter - $memoryBefore;
        
        // Assert result is correct
        $this->assertTrue($result->isOk());
        $avg = $result->unwrap()->unwrap();
        $this->assertIsFloat($avg);
        $this->assertGreaterThan(0, $avg);
        
        // Memory should be low (only storing sum and count accumulators)
        $this->assertLessThan(5 * 1024 * 1024, $memoryUsed,
            "Avg aggregate memory usage ({$memoryUsed} bytes) exceeded 5MB for 30k rows");
    }

    #[Test]
    public function performance_comparison_across_file_sizes(): void
    {
        $results = [];
        
        // Test with 1k, 5k, 10k rows
        foreach ([1000, 5000, 10000] as $rowCount) {
            $csvPath = sys_get_temp_dir() . '/perf_test_' . $rowCount . '_' . uniqid() . '.csv';
            $this->createLargeCsv($csvPath, $rowCount);
            
            $startTime = microtime(true);
            $memoryBefore = memory_get_usage();
            
            $source = new LookupSource(
                filePath: $csvPath,
                filters: [new ValueFilter('category', new StaticSource('Electronics'))],
                aggregate: 'count',
            );
            
            $result = $this->resolver->resolve($source);
            
            $executionTime = microtime(true) - $startTime;
            $memoryUsed = memory_get_usage() - $memoryBefore;
            
            $results[$rowCount] = [
                'time' => $executionTime,
                'memory' => $memoryUsed,
                'count' => $result->unwrap()->unwrap(),
            ];
            
            unlink($csvPath);
        }
        
        // Assert memory usage scales linearly or better (not quadratically)
        // Memory ratio should be close to row count ratio
        $memory1k = $results[1000]['memory'];
        $memory10k = $results[10000]['memory'];
        
        // Memory should not grow more than 15x when rows grow 10x
        // (allows for some overhead but prevents O(n) array storage)
        $memoryRatio = $memory10k / max($memory1k, 1);
        $this->assertLessThan(15, $memoryRatio,
            "Memory ratio ({$memoryRatio}) suggests non-constant memory usage");
        
        // Execution time should scale roughly linearly with row count
        $time1k = $results[1000]['time'];
        $time10k = $results[10000]['time'];
        $timeRatio = $time10k / max($time1k, 0.001);
        
        // Time ratio should be between 5x and 20x for 10x rows (allowing for variance)
        $this->assertLessThan(20, $timeRatio,
            "Time ratio ({$timeRatio}) suggests poor performance scaling");
    }

    /**
     * Create a large CSV file with the specified number of rows
     */
    private function createLargeCsv(string $path, int $rowCount): void
    {
        $handle = fopen($path, 'w');
        
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
