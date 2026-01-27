<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Benchmarks;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PhpBench\Attributes\{BeforeMethods, Groups, Iterations, Revs, Warmup};
use Superscript\Axiom\Lookup\Resolvers\{DelegatingResolver, LookupResolver, StaticResolver};
use Superscript\Axiom\Lookup\Sources\{ValueFilter, LookupSource, RangeFilter, StaticSource};
use Superscript\Axiom\Lookup\SymbolRegistry;

/**
 * Benchmarks for CSV/TSV lookup resolver performance characteristics.
 *
 * Run with: vendor/bin/phpbench run benchmarks/LookupResolverBench.php --report=default
 */
class LookupResolverBench
{
    private Filesystem $filesystem;
    private string $smallCsvFilename;
    private string $mediumCsvFilename;
    private string $largeCsvFilename;
    private string $hugeCsvFilename;
    private DelegatingResolver $resolver;

    public function setUp(): void
    {
        // Set up Flysystem
        $adapter = new LocalFilesystemAdapter(sys_get_temp_dir());
        $this->filesystem = new Filesystem($adapter);

        // Create test CSV files with different sizes
        $this->smallCsvFilename = basename(tempnam(sys_get_temp_dir(), 'bench_small_')) . '.csv';
        $this->mediumCsvFilename = basename(tempnam(sys_get_temp_dir(), 'bench_medium_')) . '.csv';
        $this->largeCsvFilename = basename(tempnam(sys_get_temp_dir(), 'bench_large_')) . '.csv';
        $this->hugeCsvFilename = basename(tempnam(sys_get_temp_dir(), 'bench_huge_')) . '.csv';

        $this->createCsvFile($this->smallCsvFilename, 100);
        $this->createCsvFile($this->mediumCsvFilename, 1000);
        $this->createCsvFile($this->largeCsvFilename, 10000);
        $this->createCsvFile($this->hugeCsvFilename, 100000);

        // Set up resolver
        $this->resolver = new DelegatingResolver([
            LookupSource::class => LookupResolver::class,
            StaticSource::class => StaticResolver::class,
        ]);
        $this->resolver->instance(SymbolRegistry::class, new SymbolRegistry([]));
        $this->resolver->instance(\League\Flysystem\FilesystemOperator::class, $this->filesystem);
    }

    public function tearDown(): void
    {
        if ($this->filesystem->fileExists($this->smallCsvFilename)) {
            $this->filesystem->delete($this->smallCsvFilename);
        }
        if ($this->filesystem->fileExists($this->mediumCsvFilename)) {
            $this->filesystem->delete($this->mediumCsvFilename);
        }
        if ($this->filesystem->fileExists($this->largeCsvFilename)) {
            $this->filesystem->delete($this->largeCsvFilename);
        }
        if ($this->filesystem->fileExists($this->hugeCsvFilename)) {
            $this->filesystem->delete($this->hugeCsvFilename);
        }
    }

    private function createCsvFile(string $filename, int $rows): void
    {
        $path = sys_get_temp_dir() . '/' . $filename;
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
            path: $this->smallCsvFilename,
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
            path: $this->mediumCsvFilename,
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
            path: $this->largeCsvFilename,
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
            path: $this->hugeCsvFilename,
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
            path: $this->largeCsvFilename,
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
            path: $this->largeCsvFilename,
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
            path: $this->largeCsvFilename,
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
            path: $this->largeCsvFilename,
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
            path: $this->largeCsvFilename,
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
            path: $this->largeCsvFilename,
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
            path: $this->largeCsvFilename,
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
            path: $this->mediumCsvFilename,
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
            path: $this->largeCsvFilename,
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
            path: $this->hugeCsvFilename,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            aggregate: 'count',
        );

        $this->resolver->resolve($source);
    }
}