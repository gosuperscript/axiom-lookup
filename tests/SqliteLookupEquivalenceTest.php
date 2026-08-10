<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Tests;

use Closure;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Axiom\Lookup\LookupExtension;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Readers\FullCsvScanLookupSourceReader;
use Superscript\Axiom\Lookup\Readers\LookupSourceReader;
use Superscript\Axiom\Lookup\Readers\SqliteLookupSourceReader;
use Superscript\Axiom\Lookup\Readers\StrategyLookupSourceReader;
use Superscript\Axiom\Lookup\Support\Aggregates;
use Superscript\Axiom\Lookup\Support\Filters\CompiledFilter;
use Superscript\Axiom\Lookup\Support\Filters\Filter;
use Superscript\Axiom\Lookup\Support\Filters\RangeFilter;
use Superscript\Axiom\Lookup\Support\Filters\ResolvedFilter;
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Lookup\Support\Sqlite\SqliteLookupConverter;
use Superscript\Axiom\Lookup\Support\Sqlite\SqliteLookupDescription;
use Superscript\Axiom\Lookup\Support\Sqlite\SqliteLookupFormat;
use Superscript\Axiom\Lookup\Support\Sqlite\SqliteSidecar;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\Coerce;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Types\LiteralType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Monads\Result\Result;

/**
 * The load-bearing guarantee of the sidecar: declaring an index on a source
 * must never change what a lookup returns — only where the file is read.
 * Every case runs the same lookup twice, forced through the full CSV scan
 * and through the default sidecar strategy, and demands identical results.
 */
#[CoversClass(LookupExtension::class)]
#[CoversClass(SqliteLookupSourceReader::class)]
#[CoversClass(StrategyLookupSourceReader::class)]
#[CoversClass(FullCsvScanLookupSourceReader::class)]
#[CoversClass(SqliteLookupConverter::class)]
#[CoversClass(SqliteLookupDescription::class)]
#[CoversClass(SqliteLookupFormat::class)]
#[CoversClass(SqliteSidecar::class)]
#[UsesClass(LookupSource::class)]
#[UsesClass(CsvRecord::class)]
#[UsesClass(ValueFilter::class)]
#[UsesClass(RangeFilter::class)]
#[UsesClass(CompiledFilter::class)]
#[UsesClass(ResolvedFilter::class)]
#[UsesClass(Aggregates\First::class)]
#[UsesClass(Aggregates\Last::class)]
#[UsesClass(Aggregates\Count::class)]
#[UsesClass(Aggregates\Sum::class)]
#[UsesClass(Aggregates\Avg::class)]
#[UsesClass(Aggregates\Min::class)]
#[UsesClass(Aggregates\Max::class)]
#[UsesClass(Aggregates\All::class)]
#[UsesClass(Aggregates\AggregateFactory::class)]
#[UsesClass(Aggregates\AggregateKind::class)]
final class SqliteLookupEquivalenceTest extends TestCase
{
    private string $workspace;

    private Filesystem $filesystem;

    private string $path;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/axiom-lookup-equivalence-' . uniqid();
        mkdir($this->workspace, recursive: true);
        $this->filesystem = new Filesystem(new LocalFilesystemAdapter($this->workspace));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->workspace));
    }

    /**
     * @param list<int|string> $publish index columns to publish a sidecar
     *        for at ingest time; empty leaves the on-the-fly build to it.
     */
    private function ingest(string $csv, array $publish = []): void
    {
        $this->path = uniqid() . '.csv';
        $this->filesystem->write($this->path, $csv);

        if ($publish !== []) {
            new SqliteSidecar()->publish(
                $this->filesystem,
                $this->path,
                $publish,
                workDirectory: $this->workspace,
            );
        }
    }

    /**
     * @param array<Filter> $filters
     * @param array<string|int> $columns
     * @param array<string|int, Type> $schema
     */
    private function lookup(
        array $filters = [],
        array $columns = [],
        string $aggregate = 'first',
        string|int|null $aggregateColumn = null,
        string $delimiter = ',',
        bool $hasHeader = true,
        array $schema = [],
        string|int|null $index = null,
    ): LookupSource {
        return new LookupSource(
            path: $this->path,
            filters: $filters,
            columns: $columns,
            aggregate: $aggregate,
            aggregateColumn: $aggregateColumn,
            delimiter: $delimiter,
            hasHeader: $hasHeader,
            schema: $schema,
            index: $index,
        );
    }

    /**
     * @param array<string, Type> $declarations
     * @return Result<mixed, \Throwable>
     */
    private function execute(
        LookupSource $source,
        ?LookupSourceReader $reader = null,
        array $declarations = [],
        mixed $bindings = null,
    ): Result {
        $extension = new LookupExtension($this->filesystem, $reader ?? new StrategyLookupSourceReader(
            new SqliteLookupSourceReader($this->filesystem, "{$this->workspace}/cache"),
            new FullCsvScanLookupSourceReader($this->filesystem),
        ));

        $dialect = Dialect::core()->with($extension);
        $program = new Expression($source, dialect: $dialect, declarations: $declarations)->compile()->unwrap();

        return $bindings === null ? $program() : $program($bindings);
    }

    /**
     * Run the same lookup through the forced scan and the sidecar strategy
     * and demand identical results; returns the shared payload so callers
     * can also pin the value.
     */
    private function assertEquivalent(LookupSource $source): mixed
    {
        $scan = $this->execute($source, new FullCsvScanLookupSourceReader($this->filesystem));
        $sidecar = $this->execute($source);

        $this->assertTrue($scan->isOk(), 'Full scan lookup failed.');
        $this->assertTrue($sidecar->isOk(), 'Sidecar lookup failed.');
        $this->assertEquals($scan->unwrap(), $sidecar->unwrap());

        return $sidecar->unwrap();
    }

    private function filter(string|int $column, Source $value, string $operator = '=='): ValueFilter
    {
        return new ValueFilter($column, $value, $operator);
    }

    #[Test]
    public function an_indexed_probe_answers_like_the_scan(): void
    {
        $this->ingest("name,age,city\nAlice,30,NYC\nBob,25,LDN\nCara,41,BER\n");

        $result = $this->assertEquivalent($this->lookup(
            filters: [$this->filter('name', new StaticSource('Bob'))],
            columns: ['age'],
            index: 'name',
        ));

        $this->assertEquals('25', $result->unwrap());
    }

    #[Test]
    public function a_published_sidecar_answers_like_the_scan(): void
    {
        $this->ingest("name,age\nAlice,30\nBob,25\n", publish: ['name']);

        $result = $this->assertEquivalent($this->lookup(
            filters: [$this->filter('name', new StaticSource('Alice'))],
            columns: ['age'],
            index: 'name',
        ));

        $this->assertEquals('30', $result->unwrap());
    }

    #[Test]
    public function a_missing_key_is_none_on_both_sides(): void
    {
        $this->ingest("name,age\nAlice,30\n");

        $result = $this->assertEquivalent($this->lookup(
            filters: [$this->filter('name', new StaticSource('Zoe'))],
            columns: ['age'],
            index: 'name',
        ));

        $this->assertTrue($result->isNone());
    }

    #[Test]
    public function duplicate_keys_keep_file_order_for_first_last_and_all(): void
    {
        $this->ingest("sku,price\nA,10\nB,20\nA,30\nA,40\nB,50\n");

        foreach (['first' => '10', 'last' => '40'] as $aggregate => $expected) {
            $result = $this->assertEquivalent($this->lookup(
                filters: [$this->filter('sku', new StaticSource('A'))],
                columns: ['price'],
                aggregate: $aggregate,
                index: 'sku',
            ));

            $this->assertEquals($expected, $result->unwrap());
        }

        $all = $this->assertEquivalent($this->lookup(
            filters: [$this->filter('sku', new StaticSource('A'))],
            columns: ['price'],
            aggregate: 'all',
            index: 'sku',
        ));

        $this->assertEquals(['10', '30', '40'], $all->unwrap());
    }

    #[Test]
    public function numeric_aggregates_agree_when_only_a_range_filter_applies(): void
    {
        $this->ingest("band,min,max,rate\nlow,0,100,5\nmid,100,200,7\nhigh,200,300,9\n");

        foreach (['sum', 'avg', 'min', 'max', 'count'] as $aggregate) {
            $result = $this->assertEquivalent($this->lookup(
                filters: [new RangeFilter('min', 'max', new StaticSource(150))],
                columns: ['rate'],
                aggregate: $aggregate,
                aggregateColumn: 'rate',
                schema: ['min' => new NumberType(), 'max' => new NumberType()],
                index: 'band',
            ));

            $this->assertEquals($aggregate === 'count' ? 1 : 7.0, $result->unwrap());
        }
    }

    #[Test]
    public function a_not_equals_filter_on_the_indexed_column_is_never_pushed(): void
    {
        $this->ingest("name,age\nAlice,30\nBob,25\n");

        $result = $this->assertEquivalent($this->lookup(
            filters: [$this->filter('name', new StaticSource('Alice'), '!=')],
            columns: ['name'],
            index: 'name',
        ));

        $this->assertEquals('Bob', $result->unwrap());
    }

    #[Test]
    public function an_equality_on_an_unindexed_column_still_agrees(): void
    {
        $this->ingest("name,city\nAlice,NYC\nBob,LDN\nCara,NYC\n");

        $result = $this->assertEquivalent($this->lookup(
            filters: [$this->filter('city', new StaticSource('NYC'))],
            aggregate: 'count',
            index: 'name',
        ));

        $this->assertEquals(2, $result->unwrap());
    }

    #[Test]
    public function a_number_typed_index_never_probes_and_still_agrees(): void
    {
        $this->ingest("code,value\n7,a\n7.0,b\n");

        $result = $this->assertEquivalent($this->lookup(
            filters: [$this->filter('code', new StaticSource(7))],
            columns: ['value'],
            aggregate: 'all',
            schema: ['code' => new NumberType()],
            index: 'code',
        ));

        // The dialect's numeric equality matches both spellings — byte
        // equality would not, which is exactly why a number-typed column is
        // answered by the filter pipeline instead of a probe.
        $this->assertEquals(['a', 'b'], $result->unwrap());
    }

    #[Test]
    public function a_column_type_that_can_reject_a_cell_is_never_probed(): void
    {
        // Narrowing decides which rows the filter pipeline ever sees, and a
        // row it never sees cannot raise its type error. LiteralType admits
        // only its own value, so the '7.0' cell is a failure the scan reports
        // — a probe on this column would read past it and answer where the
        // scan refuses. Equivalence has to cover how a lookup fails, not just
        // what it returns.
        $this->ingest("code,value\n7,a\n7.0,b\n");

        $source = $this->lookup(
            filters: [$this->filter('code', new StaticSource('7'))],
            columns: ['value'],
            aggregate: 'all',
            schema: ['code' => new LiteralType('7')],
            index: 'code',
        );

        $scan = $this->execute($source, new FullCsvScanLookupSourceReader($this->filesystem));
        $sidecar = $this->execute($source);

        $this->assertTrue($scan->isErr(), 'The scan reads every row and reports the type error.');
        $this->assertTrue($sidecar->isErr(), 'The sidecar must report it too, not narrow past it.');
        $this->assertEquals($scan->unwrapErr()->getMessage(), $sidecar->unwrapErr()->getMessage());
    }

    #[Test]
    public function headerless_files_probe_by_position(): void
    {
        $this->ingest("A,10\nB,20\nA,30\n");

        $result = $this->assertEquivalent($this->lookup(
            filters: [$this->filter(0, new StaticSource('A'))],
            columns: [1],
            aggregate: 'all',
            hasHeader: false,
            index: 0,
        ));

        $this->assertEquals(['10', '30'], $result->unwrap());
    }

    #[Test]
    public function ragged_headerless_files_reconstruct_identically(): void
    {
        $this->ingest("k,2,3\n4,5\nk,7,8,9\n");

        $result = $this->assertEquivalent($this->lookup(
            filters: [$this->filter(0, new StaticSource('k'))],
            aggregate: 'all',
            hasHeader: false,
            index: 0,
        ));

        $this->assertEquals([
            [0 => 'k', 1 => '2', 2 => '3'],
            [0 => 'k', 1 => '7', 2 => '8', 3 => '9'],
        ], $result->unwrap());
    }

    #[Test]
    public function short_rows_under_a_header_stay_padded(): void
    {
        $this->ingest("a,b,c\n1,2,3\n4,5\n");

        $result = $this->assertEquivalent($this->lookup(
            filters: [$this->filter('a', new StaticSource('4'))],
            aggregate: 'all',
            index: 'a',
        ));

        $this->assertEquals([['a' => '4', 'b' => '5', 'c' => null]], $result->unwrap());
    }

    #[Test]
    public function bom_and_alternate_delimiters_agree(): void
    {
        $this->ingest("\u{FEFF}name;age\nAlice;30\n");

        $result = $this->assertEquivalent($this->lookup(
            filters: [$this->filter('name', new StaticSource('Alice'))],
            columns: ['age'],
            delimiter: ';',
            index: 'name',
        ));

        $this->assertEquals('30', $result->unwrap());
    }

    #[Test]
    public function raw_bytes_probe_exactly(): void
    {
        $latin = "\xE9t\xE9";
        $this->ingest("season,code\n{$latin},1\nother,2\n");

        $result = $this->assertEquivalent($this->lookup(
            filters: [$this->filter('season', new StaticSource($latin))],
            columns: ['code'],
            index: 'season',
        ));

        $this->assertEquals('1', $result->unwrap());
    }

    #[Test]
    public function empty_string_cells_probe_exactly(): void
    {
        $this->ingest("key,value\n,blank\nfull,present\n");

        $result = $this->assertEquivalent($this->lookup(
            filters: [$this->filter('key', new StaticSource(''))],
            columns: ['value'],
            index: 'key',
        ));

        $this->assertEquals('blank', $result->unwrap());
    }

    #[Test]
    public function lookalike_header_names_stay_distinct(): void
    {
        $this->ingest("0,00\nx,y\np,q\n");

        $result = $this->assertEquivalent($this->lookup(
            filters: [$this->filter('00', new StaticSource('q'))],
            columns: ['0'],
            index: '00',
        ));

        $this->assertEquals('p', $result->unwrap());
    }

    #[Test]
    public function a_file_with_only_a_header_agrees_on_emptiness(): void
    {
        $this->ingest("name,age\n");

        $first = $this->assertEquivalent($this->lookup(
            filters: [$this->filter('name', new StaticSource('Alice'))],
            index: 'name',
        ));

        $this->assertTrue($first->isNone());
    }

    #[Test]
    public function an_empty_headerless_file_agrees_on_emptiness(): void
    {
        // Indexing position 0 of a zero-column file refuses to build; the
        // reader declines and the scan answers — equivalence by fallback.
        $this->ingest('');

        $result = $this->assertEquivalent($this->lookup(
            filters: [$this->filter(0, new StaticSource('x'))],
            hasHeader: false,
            index: 0,
        ));

        $this->assertTrue($result->isNone());
    }

    #[Test]
    public function a_nested_lookup_can_feed_a_probe(): void
    {
        $this->ingest("city,mayor\nNYC,Alice\nLDN,Bob\n");
        $mayors = $this->path;

        $this->ingest("name,age\nAlice,30\nBob,25\n");

        $mayor = new LookupSource(
            path: $mayors,
            filters: [$this->filter('city', new StaticSource('LDN'))],
            columns: ['mayor'],
        );

        // The inner lookup declares Option<Unknown>; the Coerce bridges it
        // to the string the outer probe seeks, as any consumer would.
        $result = $this->assertEquivalent($this->lookup(
            filters: [$this->filter('name', new Coerce(new StringType(), $mayor))],
            columns: ['age'],
            index: 'name',
        ));

        $this->assertEquals('25', $result->unwrap());
    }

    #[Test]
    public function symbol_probes_resolve_per_invocation(): void
    {
        $this->ingest("name,age\nAlice,30\nBob,25\n");

        $source = $this->lookup(
            filters: [$this->filter('name', new SymbolSource('who'))],
            columns: ['age'],
            index: 'name',
        );

        $extension = new LookupExtension($this->filesystem, new StrategyLookupSourceReader(
            new SqliteLookupSourceReader($this->filesystem, "{$this->workspace}/cache"),
            new FullCsvScanLookupSourceReader($this->filesystem),
        ));

        $program = new Expression(
            $source,
            dialect: Dialect::core()->with($extension),
            declarations: ['who' => new StringType()],
        )->compile()->unwrap();

        $this->assertEquals('30', $program(['who' => 'Alice'])->unwrap()->unwrap());
        $this->assertEquals('25', $program(['who' => 'Bob'])->unwrap()->unwrap());
    }

    #[Test]
    public function the_default_wiring_answers_an_indexed_lookup_by_itself(): void
    {
        $this->ingest("name,age\nAlice,30\n");

        $result = new Expression(
            $this->lookup(
                filters: [$this->filter('name', new StaticSource('Alice'))],
                columns: ['age'],
                index: 'name',
            ),
            dialect: Dialect::core()->with(new LookupExtension($this->filesystem)),
        )->compile()->unwrap()();

        $this->assertTrue($result->isOk());
        $this->assertEquals('30', $result->unwrap()->unwrap());
    }

    #[Test]
    public function an_injected_reader_owns_the_whole_read(): void
    {
        $this->ingest("name,age\nAlice,30\n");

        $reader = new class implements LookupSourceReader {
            public function findRecords(LookupSource $source, array $probes, ?Closure $scanned = null): iterable
            {
                yield ['name' => 'Alice', 'age' => '99'];
            }
        };

        $result = $this->execute(
            $this->lookup(
                filters: [$this->filter('name', new StaticSource('Alice'))],
                columns: ['age'],
            ),
            $reader,
        );

        $this->assertEquals('99', $result->unwrap()->unwrap());
    }
}
