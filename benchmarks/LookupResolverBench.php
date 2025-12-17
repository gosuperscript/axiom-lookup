<?php

declare(strict_types=1);

namespace Superscript\Schema\Lookup\Benchmarks;

use Illuminate\Container\Container;
use PhpBench\Attributes\{BeforeMethods, Groups, Iterations, Revs, Warmup};
use Superscript\Schema\Lookup\Resolvers\{DelegatingResolver, LookupResolver, StaticResolver};
use Superscript\Schema\Lookup\Sources\{ValueFilter, LookupSource, RangeFilter, StaticSource};
use Superscript\Schema\Lookup\SymbolRegistry;

/**
 * Benchmarks for CSV/TSV lookup resolver performance characteristics.
 *
 * Run with: vendor/bin/phpbench run benchmarks/LookupResolverBench.php --report=default
 */
class LookupResolverBench
{
    private string $smallCsv;
    private string $mediumCsv;
    private string $largeCsv;
    private string $hugeCsv;
    private DelegatingResolver $resolver;

    public function setUp(): void
    {
        // Create test CSV files with different sizes
        $this->smallCsv = tempnam(sys_get_temp_dir(), 'bench_small_') . '.csv';
        $this->mediumCsv = tempnam(sys_get_temp_dir(), 'bench_medium_') . '.csv';
        $this->largeCsv = tempnam(sys_get_temp_dir(), 'bench_large_') . '.csv';
        $this->hugeCsv = tempnam(sys_get_temp_dir(), 'bench_huge_') . '.csv';

        $this->createCsvFile($this->smallCsv, 100);
        $this->createCsvFile($this->mediumCsv, 1000);
        $this->createCsvFile($this->largeCsv, 10000);
        $this->createCsvFile($this->hugeCsv, 100000);

        // Set up resolver
        $this->resolver = new DelegatingResolver([
            LookupSource::class => LookupResolver::class,
            StaticSource::class => StaticResolver::class,
        ]);
        $this->resolver->instance(SymbolRegistry::class, new SymbolRegistry([]));
    }

    public function tearDown(): void
    {
        @unlink($this->smallCsv);
        @unlink($this->mediumCsv);
        @unlink($this->largeCsv);
        @unlink($this->hugeCsv);
    }

    private function createCsvFile(string $path, int $rows): void
    {
        $fp = fopen($path, 'w');
        fputcsv($fp, ['id', 'name', 'category', 'price', 'quantity'], escape: '\\');

        $categories = ['Electronics', 'Books', 'Clothing', 'Food', 'Toys'];

        for ($i = 1; $i <= $rows; $i++) {
            fputcsv($fp, [
                $i,
                "Product {$i}",
                $categories[$i % 5],
                rand(10, 1000),
                rand(1, 100),
            ], escape: '\\');
        }

        fclose($fp);
    }

    #[BeforeMethods('setUp')]
    #[Groups(['exact', 'small'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchExactFilterSmallFile(): void
    {
        $source = new LookupSource(
            filePath: $this->smallCsv,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            columns: ['name', 'price'],
        );

        $this->resolver->resolve($source);
    }

    #[BeforeMethods('setUp')]
    #[Groups(['exact', 'medium'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchExactFilterMediumFile(): void
    {
        $source = new LookupSource(
            filePath: $this->mediumCsv,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            columns: ['name', 'price'],
        );

        $this->resolver->resolve($source);
    }

    #[BeforeMethods('setUp')]
    #[Groups(['exact', 'large'])]
    #[Iterations(5)]
    #[Revs(5)]
    #[Warmup(1)]
    public function benchExactFilterLargeFile(): void
    {
        $source = new LookupSource(
            filePath: $this->largeCsv,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            columns: ['name', 'price'],
        );

        $this->resolver->resolve($source);
    }

    #[BeforeMethods('setUp')]
    #[Groups(['exact', 'huge'])]
    #[Iterations(3)]
    #[Revs(3)]
    #[Warmup(1)]
    public function benchExactFilterHugeFile(): void
    {
        $source = new LookupSource(
            filePath: $this->hugeCsv,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            columns: ['name', 'price'],
        );

        $this->resolver->resolve($source);
    }

    #[BeforeMethods('setUp')]
    #[Groups(['aggregate', 'first'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchFirstAggregate(): void
    {
        $source = new LookupSource(
            filePath: $this->largeCsv,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            columns: ['name', 'price'],
            aggregate: 'first',
        );

        $this->resolver->resolve($source);
    }

    #[BeforeMethods('setUp')]
    #[Groups(['aggregate', 'last'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchLastAggregate(): void
    {
        $source = new LookupSource(
            filePath: $this->largeCsv,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            columns: ['name', 'price'],
            aggregate: 'last',
        );

        $this->resolver->resolve($source);
    }

    #[BeforeMethods('setUp')]
    #[Groups(['aggregate', 'count'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchCountAggregate(): void
    {
        $source = new LookupSource(
            filePath: $this->largeCsv,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            aggregate: 'count',
        );

        $this->resolver->resolve($source);
    }

    #[BeforeMethods('setUp')]
    #[Groups(['aggregate', 'sum'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchSumAggregate(): void
    {
        $source = new LookupSource(
            filePath: $this->largeCsv,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            aggregate: 'sum',
            aggregateColumn: 'price',
        );

        $this->resolver->resolve($source);
    }

    #[BeforeMethods('setUp')]
    #[Groups(['aggregate', 'avg'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchAvgAggregate(): void
    {
        $source = new LookupSource(
            filePath: $this->largeCsv,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            aggregate: 'avg',
            aggregateColumn: 'price',
        );

        $this->resolver->resolve($source);
    }

    #[BeforeMethods('setUp')]
    #[Groups(['aggregate', 'min'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchMinAggregate(): void
    {
        $source = new LookupSource(
            filePath: $this->largeCsv,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            columns: ['name', 'price'],
            aggregate: 'min',
            aggregateColumn: 'price',
        );

        $this->resolver->resolve($source);
    }

    #[BeforeMethods('setUp')]
    #[Groups(['aggregate', 'max'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchMaxAggregate(): void
    {
        $source = new LookupSource(
            filePath: $this->largeCsv,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            columns: ['name', 'price'],
            aggregate: 'max',
            aggregateColumn: 'price',
        );

        $this->resolver->resolve($source);
    }

    #[BeforeMethods('setUp')]
    #[Groups(['range', 'banding'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchRangeFilter(): void
    {
        $source = new LookupSource(
            filePath: $this->mediumCsv,
            filters: [new RangeFilter('price', 'price', new StaticSource('500'))],
            columns: ['name', 'price'],
        );

        $this->resolver->resolve($source);
    }

    #[BeforeMethods('setUp')]
    #[Groups(['complex', 'multi-filter'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchMultipleFilters(): void
    {
        $source = new LookupSource(
            filePath: $this->largeCsv,
            filters: [
                new ValueFilter('category', new StaticSource('Electronics')),
                new RangeFilter('price', 'price', new StaticSource('500')),
            ],
            columns: ['name', 'price'],
        );

        $this->resolver->resolve($source);
    }

    #[BeforeMethods('setUp')]
    #[Groups(['memory', 'streaming'])]
    #[Iterations(3)]
    #[Revs(3)]
    #[Warmup(1)]
    public function benchStreamingMemoryEfficiency(): void
    {
        // This benchmark tests memory efficiency with a huge file
        $source = new LookupSource(
            filePath: $this->hugeCsv,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            aggregate: 'count',
        );

        $this->resolver->resolve($source);
    }
}
