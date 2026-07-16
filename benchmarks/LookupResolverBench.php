<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Benchmarks;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PhpBench\Attributes\{BeforeMethods, Groups, Iterations, Revs, Warmup};
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Lookup\LookupExtension;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Support\Filters\{RangeFilter, ValueFilter};
use Superscript\Axiom\Sources\StaticSource;

/**
 * Benchmarks for CSV/TSV lookup performance characteristics.
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

    /**
     * Compile the lookup and invoke the resulting program — the whole
     * compile-then-run path a host takes.
     */
    private function resolve(LookupSource $source): void
    {
        $dialect = Dialect::core()->with(new LookupExtension($this->filesystem));

        (new Expression($source, dialect: $dialect))->compile()->unwrap()();
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
        $this->resolve(new LookupSource(
            path: $this->smallCsvFilename,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            columns: ['name', 'price'],
        ));
    }

    #[BeforeMethods('setUp')]
    #[Groups(['exact', 'medium'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchExactFilterMediumFile(): void
    {
        $this->resolve(new LookupSource(
            path: $this->mediumCsvFilename,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            columns: ['name', 'price'],
        ));
    }

    #[BeforeMethods('setUp')]
    #[Groups(['exact', 'large'])]
    #[Iterations(5)]
    #[Revs(5)]
    #[Warmup(1)]
    public function benchExactFilterLargeFile(): void
    {
        $this->resolve(new LookupSource(
            path: $this->largeCsvFilename,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            columns: ['name', 'price'],
        ));
    }

    #[BeforeMethods('setUp')]
    #[Groups(['exact', 'huge'])]
    #[Iterations(3)]
    #[Revs(3)]
    #[Warmup(1)]
    public function benchExactFilterHugeFile(): void
    {
        $this->resolve(new LookupSource(
            path: $this->hugeCsvFilename,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            columns: ['name', 'price'],
        ));
    }

    #[BeforeMethods('setUp')]
    #[Groups(['aggregate', 'first'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchFirstAggregate(): void
    {
        $this->resolve(new LookupSource(
            path: $this->largeCsvFilename,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            columns: ['name', 'price'],
            aggregate: 'first',
        ));
    }

    #[BeforeMethods('setUp')]
    #[Groups(['aggregate', 'last'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchLastAggregate(): void
    {
        $this->resolve(new LookupSource(
            path: $this->largeCsvFilename,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            columns: ['name', 'price'],
            aggregate: 'last',
        ));
    }

    #[BeforeMethods('setUp')]
    #[Groups(['aggregate', 'count'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchCountAggregate(): void
    {
        $this->resolve(new LookupSource(
            path: $this->largeCsvFilename,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            aggregate: 'count',
        ));
    }

    #[BeforeMethods('setUp')]
    #[Groups(['aggregate', 'sum'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchSumAggregate(): void
    {
        $this->resolve(new LookupSource(
            path: $this->largeCsvFilename,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            aggregate: 'sum',
            aggregateColumn: 'price',
        ));
    }

    #[BeforeMethods('setUp')]
    #[Groups(['aggregate', 'avg'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchAvgAggregate(): void
    {
        $this->resolve(new LookupSource(
            path: $this->largeCsvFilename,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            aggregate: 'avg',
            aggregateColumn: 'price',
        ));
    }

    #[BeforeMethods('setUp')]
    #[Groups(['aggregate', 'min'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchMinAggregate(): void
    {
        $this->resolve(new LookupSource(
            path: $this->largeCsvFilename,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            columns: ['name', 'price'],
            aggregate: 'min',
            aggregateColumn: 'price',
        ));
    }

    #[BeforeMethods('setUp')]
    #[Groups(['aggregate', 'max'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchMaxAggregate(): void
    {
        $this->resolve(new LookupSource(
            path: $this->largeCsvFilename,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            columns: ['name', 'price'],
            aggregate: 'max',
            aggregateColumn: 'price',
        ));
    }

    #[BeforeMethods('setUp')]
    #[Groups(['range', 'banding'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchRangeFilter(): void
    {
        $this->resolve(new LookupSource(
            path: $this->mediumCsvFilename,
            filters: [new RangeFilter('price', 'price', new StaticSource('500'))],
            columns: ['name', 'price'],
        ));
    }

    #[BeforeMethods('setUp')]
    #[Groups(['complex', 'multi-filter'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchMultipleFilters(): void
    {
        $this->resolve(new LookupSource(
            path: $this->largeCsvFilename,
            filters: [
                new ValueFilter('category', new StaticSource('Electronics')),
                new RangeFilter('price', 'price', new StaticSource('500')),
            ],
            columns: ['name', 'price'],
        ));
    }

    #[BeforeMethods('setUp')]
    #[Groups(['memory', 'streaming'])]
    #[Iterations(3)]
    #[Revs(3)]
    #[Warmup(1)]
    public function benchStreamingMemoryEfficiency(): void
    {
        // This benchmark tests memory efficiency with a huge file
        $this->resolve(new LookupSource(
            path: $this->hugeCsvFilename,
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
            aggregate: 'count',
        ));
    }
}
