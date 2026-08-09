<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Benchmarks;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PhpBench\Attributes\{BeforeMethods, Groups, Iterations, Revs, Warmup};
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Lookup\Column;
use Superscript\Axiom\Lookup\DelimitedTable;
use Superscript\Axiom\Lookup\LookupExtension;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Support\Filters\{RangeFilter, ValueFilter};
use Superscript\Axiom\Lookup\Support\Results\{
    AverageColumn,
    CountRows,
    FirstRow,
    LastRow,
    MaximumRow,
    MinimumRow,
    NumericResult,
    ProjectedResult,
    RecordProjection,
    SumColumn,
};
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Types\{NumberType, StringType};

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

    /** @param list<\Superscript\Axiom\Lookup\Support\Filters\Filter> $filters */
    private function lookup(
        string $path,
        ProjectedResult|NumericResult $result,
        array $filters = [],
    ): LookupSource {
        return new LookupSource(
            table: new DelimitedTable($path, [
                new Column('id', new NumberType()),
                new Column('name', new StringType()),
                new Column('category', new StringType()),
                new Column('price', new NumberType()),
                new Column('quantity', new NumberType()),
            ]),
            result: $result,
            filters: $filters,
        );
    }

    private function records(FirstRow|LastRow|MinimumRow|MaximumRow $rows = new FirstRow()): ProjectedResult
    {
        return new ProjectedResult($rows, new RecordProjection([
            'name' => 'name',
            'price' => 'price',
        ]));
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
        $this->resolve($this->lookup(
            path: $this->smallCsvFilename,
            result: $this->records(),
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
        ));
    }

    #[BeforeMethods('setUp')]
    #[Groups(['exact', 'medium'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchExactFilterMediumFile(): void
    {
        $this->resolve($this->lookup(
            path: $this->mediumCsvFilename,
            result: $this->records(),
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
        ));
    }

    #[BeforeMethods('setUp')]
    #[Groups(['exact', 'large'])]
    #[Iterations(5)]
    #[Revs(5)]
    #[Warmup(1)]
    public function benchExactFilterLargeFile(): void
    {
        $this->resolve($this->lookup(
            path: $this->largeCsvFilename,
            result: $this->records(),
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
        ));
    }

    #[BeforeMethods('setUp')]
    #[Groups(['exact', 'huge'])]
    #[Iterations(3)]
    #[Revs(3)]
    #[Warmup(1)]
    public function benchExactFilterHugeFile(): void
    {
        $this->resolve($this->lookup(
            path: $this->hugeCsvFilename,
            result: $this->records(),
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
        ));
    }

    #[BeforeMethods('setUp')]
    #[Groups(['result', 'first'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchFirstRow(): void
    {
        $this->resolve($this->lookup(
            path: $this->largeCsvFilename,
            result: $this->records(),
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
        ));
    }

    #[BeforeMethods('setUp')]
    #[Groups(['result', 'last'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchLastRow(): void
    {
        $this->resolve($this->lookup(
            path: $this->largeCsvFilename,
            result: $this->records(new LastRow()),
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
        ));
    }

    #[BeforeMethods('setUp')]
    #[Groups(['result', 'count'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchCountRows(): void
    {
        $this->resolve($this->lookup(
            path: $this->largeCsvFilename,
            result: new NumericResult(new CountRows()),
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
        ));
    }

    #[BeforeMethods('setUp')]
    #[Groups(['result', 'sum'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchSumColumn(): void
    {
        $this->resolve($this->lookup(
            path: $this->largeCsvFilename,
            result: new NumericResult(new SumColumn('price')),
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
        ));
    }

    #[BeforeMethods('setUp')]
    #[Groups(['result', 'average'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchAverageColumn(): void
    {
        $this->resolve($this->lookup(
            path: $this->largeCsvFilename,
            result: new NumericResult(new AverageColumn('price')),
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
        ));
    }

    #[BeforeMethods('setUp')]
    #[Groups(['result', 'minimum'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchMinimumRow(): void
    {
        $this->resolve($this->lookup(
            path: $this->largeCsvFilename,
            result: $this->records(new MinimumRow('price')),
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
        ));
    }

    #[BeforeMethods('setUp')]
    #[Groups(['result', 'maximum'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchMaximumRow(): void
    {
        $this->resolve($this->lookup(
            path: $this->largeCsvFilename,
            result: $this->records(new MaximumRow('price')),
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
        ));
    }

    #[BeforeMethods('setUp')]
    #[Groups(['range', 'banding'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchRangeFilter(): void
    {
        $this->resolve($this->lookup(
            path: $this->mediumCsvFilename,
            result: $this->records(),
            filters: [new RangeFilter('price', 'price', new StaticSource('500'))],
        ));
    }

    #[BeforeMethods('setUp')]
    #[Groups(['complex', 'multi-filter'])]
    #[Iterations(5)]
    #[Revs(10)]
    #[Warmup(1)]
    public function benchMultipleFilters(): void
    {
        $this->resolve($this->lookup(
            path: $this->largeCsvFilename,
            result: $this->records(),
            filters: [
                new ValueFilter('category', new StaticSource('Electronics')),
                new RangeFilter('price', 'price', new StaticSource('500')),
            ],
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
        $this->resolve($this->lookup(
            path: $this->hugeCsvFilename,
            result: new NumericResult(new CountRows()),
            filters: [new ValueFilter('category', new StaticSource('Electronics'))],
        ));
    }
}
