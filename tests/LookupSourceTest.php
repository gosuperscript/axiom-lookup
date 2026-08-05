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
use RuntimeException;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Extension;
use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Axiom\Lookup\LookupExtension;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Support\Aggregates;
use Superscript\Axiom\Lookup\Support\Filters\CompiledFilter;
use Superscript\Axiom\Lookup\Support\Filters\Filter;
use Superscript\Axiom\Lookup\Support\Filters\RangeFilter;
use Superscript\Axiom\Lookup\Support\Filters\ResolvedFilter;
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Lookup\Readers\FullCsvScanLookupSourceReader;
use Superscript\Axiom\Lookup\Readers\IndexedCsvLookupSourceReader;
use Superscript\Axiom\Lookup\Readers\LookupSourceReader;
use Superscript\Axiom\Lookup\Readers\StrategyLookupSourceReader;
use Superscript\Axiom\Lookup\Support\CsvIndexedScan;
use Superscript\Axiom\Operators\Operator;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\Coerce;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\UnknownType;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;

#[CoversClass(LookupExtension::class)]
#[CoversClass(LookupSource::class)]
#[CoversClass(ValueFilter::class)]
#[CoversClass(RangeFilter::class)]
#[CoversClass(CompiledFilter::class)]
#[CoversClass(ResolvedFilter::class)]
#[UsesClass(CsvRecord::class)]
#[UsesClass(CsvIndexedScan::class)]
#[UsesClass(StrategyLookupSourceReader::class)]
#[UsesClass(IndexedCsvLookupSourceReader::class)]
#[UsesClass(FullCsvScanLookupSourceReader::class)]
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
     * @param array<string|int, Type> $schema
     */
    private function lookup(
        string $path,
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
            path: $path,
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
     * Compile the lookup as a whole expression and invoke it — the boundary
     * the old resolver crossed, now expressed as compile-then-run. The
     * filesystem the read needs is injected through the LookupExtension, so
     * the LookupSource itself stays pure, serialisable data.
     *
     * @return Result<Option<mixed>, \Throwable>
     */
    private function execute(LookupSource $source): Result
    {
        return $this->expression($source)->compile()->unwrap()();
    }

    /**
     * Build the expression the way a host would: the filesystem the read
     * needs is injected through the LookupExtension on the dialect, so the
     * LookupSource itself stays pure, serialisable data.
     */
    /** @param list<Extension> $extensions */
    private function expression(
        LookupSource $source,
        ?FilesystemOperator $filesystem = null,
        array $extensions = [],
    ): Expression {
        $dialect = Dialect::core()->with(
            new LookupExtension($filesystem ?? $this->filesystem),
            ...$extensions,
        );

        return new Expression($source, dialect: $dialect);
    }

    private function filter(string|int $column, Source $value, string $operator = '=='): ValueFilter
    {
        return new ValueFilter($column, $value, $operator);
    }

    /** @return array<string|int, Type> */
    private static function numbers(string|int ...$columns): array
    {
        $schema = [];

        foreach ($columns as $column) {
            $schema[$column] = new NumberType();
        }

        return $schema;
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
            filters: [$this->filter(
                'city',
                new Coerce(new OptionType(new StringType()), $cityLookup),
            )],
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
            filters: [$this->filter(
                'city',
                new Coerce(new OptionType(new StringType()), $noneSource),
            )],
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
            filters: [new RangeFilter('min_turnover', 'max_turnover', new StaticSource(150000))],
            columns: ['premium'],
            schema: self::numbers('min_turnover', 'max_turnover'),
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
            filters: [new RangeFilter('min_turnover', 'max_turnover', new StaticSource(50000))],
            columns: ['premium'],
            schema: self::numbers('min_turnover', 'max_turnover'),
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
            filters: [new RangeFilter('min_turnover', 'max_turnover', new StaticSource(500000))],
            columns: ['premium'],
            schema: self::numbers('min_turnover', 'max_turnover'),
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
            filters: [new RangeFilter('min_turnover', 'max_turnover', new StaticSource(100000))],
            columns: ['premium'],
            schema: self::numbers('min_turnover', 'max_turnover'),
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
                new RangeFilter('min_value', 'max_value', new StaticSource(150)),
            ],
            columns: ['rate'],
            schema: self::numbers('min_value', 'max_value'),
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals('10', $result->unwrap()->unwrap()); // North region, 150 in 100-200 band

        // Cleanup
        $this->filesystem->delete('regional_bands.csv');
    }

    #[Test]
    public function it_compares_with_engine_equality_not_php_juggling(): void
    {
        // '1e2' and '100' are equal under PHP's loose ==, but they are
        // distinct strings under the engine's ValueEquality. A lookup must
        // agree with the language, so this filter finds no match.
        $csvContent = "code,label\n1e2,scientific\n100,plain\n";
        $this->filesystem->write('codes.csv', $csvContent);

        $source = $this->lookup(
            path: 'codes.csv',
            filters: [$this->filter('code', new StaticSource('100'))],
            columns: ['label'],
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertSame('plain', $result->unwrap()->unwrap()); // matches '100', never '1e2'

        $this->filesystem->delete('codes.csv');
    }

    #[Test]
    public function undeclared_string_columns_preserve_empty_and_literal_null_cells(): void
    {
        $this->filesystem->write('raw_strings.csv', "key,label\n,blank\nnull,literal-null\n");

        $blank = $this->execute($this->lookup(
            path: 'raw_strings.csv',
            filters: [$this->filter('key', new StaticSource(''))],
            columns: ['label'],
        ));
        $literalNull = $this->execute($this->lookup(
            path: 'raw_strings.csv',
            filters: [$this->filter('key', new StaticSource('null'))],
            columns: ['label'],
        ));

        $this->assertSame('blank', $blank->unwrap()->unwrap());
        $this->assertSame('literal-null', $literalNull->unwrap()->unwrap());

        $this->filesystem->delete('raw_strings.csv');
    }

    #[Test]
    public function value_filters_bind_extension_owned_operators_from_the_composed_dialect(): void
    {
        $caseInsensitive = new class extends Extension {
            public function operators(): array
            {
                return [
                    Operator::infix('equals-ignore-case')
                        ->takes(new StringType(), new StringType())
                        ->returns(new BooleanType())
                        ->evaluatesWith(fn(string $left, string $right): bool => strcasecmp($left, $right) === 0),
                ];
            }
        };
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('name', new StaticSource('ALICE'), 'equals-ignore-case')],
            columns: ['age'],
        );

        $result = $this->expression($source, extensions: [$caseInsensitive])
            ->compile()->unwrap()();

        $this->assertSame('30', $result->unwrap()->unwrap());
    }

    #[Test]
    public function an_expected_filter_operation_failure_remains_the_program_error(): void
    {
        $failure = new RuntimeException('comparison failed');
        $fragile = new class ($failure) extends Extension {
            public function __construct(private RuntimeException $failure) {}

            public function operators(): array
            {
                return [
                    Operator::infix('fragile-equals')
                        ->takes(new StringType(), new StringType())
                        ->returns(new BooleanType())
                        ->evaluatesWith(fn(string $left, string $right): Result => Err($this->failure)),
                ];
            }
        };
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('name', new StaticSource('ALICE'), 'fragile-equals')],
            columns: ['age'],
        );

        $result = $this->expression($source, extensions: [$fragile])
            ->compile()->unwrap()();

        $this->assertTrue($result->isErr());
        $this->assertSame($failure, $result->unwrapErr());
    }

    #[Test]
    public function declared_numeric_columns_are_coerced_before_comparison(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('age', new StaticSource(30), '>=')],
            columns: ['name'],
            schema: self::numbers('age'),
        );

        $result = $this->execute($source);

        $this->assertSame('Alice', $result->unwrap()->unwrap());
    }

    #[Test]
    public function a_filter_operator_must_return_boolean(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('age', new StaticSource(5), '+')],
            schema: self::numbers('age'),
        );

        $result = $this->expression($source)->compile();

        $this->assertTrue($result->isErr());
        $mismatch = $result->unwrapErr();
        $this->assertStringContainsString('must return Boolean', $mismatch->describe());
        $this->assertCount(1, $mismatch->causes);
    }

    #[Test]
    public function unknown_filter_kinds_are_compile_errors(): void
    {
        $filter = new class (new StaticSource('Alice')) implements Filter {
            public function __construct(public Source $value) {}
        };
        $source = $this->lookup(path: 'users.csv', filters: [$filter]);

        $result = $this->expression($source)->compile();

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('has no compiler in LookupExtension', $result->unwrapErr()->describe());
    }

    #[Test]
    public function invalid_typed_cells_fail_at_the_lookup_boundary(): void
    {
        $this->filesystem->write('invalid_number.csv', "minimum,maximum\nnot-a-number,200\n");
        $source = $this->lookup(
            path: 'invalid_number.csv',
            filters: [new RangeFilter('minimum', 'maximum', new StaticSource(100))],
            aggregate: 'count',
            schema: self::numbers('minimum', 'maximum'),
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isErr());

        $this->filesystem->delete('invalid_number.csv');
    }

    #[Test]
    public function invalid_typed_maximum_cells_fail_at_the_lookup_boundary(): void
    {
        $this->filesystem->write('invalid_maximum.csv', "minimum,maximum\n50,not-a-number\n");
        $source = $this->lookup(
            path: 'invalid_maximum.csv',
            filters: [new RangeFilter('minimum', 'maximum', new StaticSource(100))],
            aggregate: 'count',
            schema: self::numbers('minimum', 'maximum'),
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isErr());

        $this->filesystem->delete('invalid_maximum.csv');
    }

    #[Test]
    public function value_filters_do_not_match_rows_without_their_column(): void
    {
        $this->filesystem->write('missing_value.csv', "Alice,30\n");
        $source = $this->lookup(
            path: 'missing_value.csv',
            filters: [$this->filter(2, new StaticSource('active'))],
            aggregate: 'count',
            hasHeader: false,
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isNone());

        $this->filesystem->delete('missing_value.csv');
    }

    #[Test]
    public function range_filters_do_not_match_values_below_the_minimum(): void
    {
        $this->filesystem->write('below_minimum.csv', "minimum,maximum\n100,200\n");
        $source = $this->lookup(
            path: 'below_minimum.csv',
            filters: [new RangeFilter('minimum', 'maximum', new StaticSource(99))],
            aggregate: 'count',
            schema: self::numbers('minimum', 'maximum'),
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isNone());

        $this->filesystem->delete('below_minimum.csv');
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
        );

        $result = $this->expression($source, $tempFilesystem)->compile()->unwrap()();

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
    public function a_nested_all_lookup_can_supply_a_typed_list_to_in(): void
    {
        $cities = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('name', new StaticSource('Alice'))],
            columns: ['city'],
            aggregate: 'all',
        );
        $users = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter(
                'city',
                new Coerce(new ListType(new StringType()), $cities),
                'in',
            )],
            columns: ['name'],
            aggregate: 'all',
        );

        $result = $this->execute($users);

        $this->assertSame(['Alice', 'Charlie'], $result->unwrap()->unwrap());
    }

    #[Test]
    public function an_empty_nested_all_lookup_supplies_an_empty_list_to_in(): void
    {
        $cities = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('name', new StaticSource('Peter'))],
            columns: ['city'],
            aggregate: 'all',
        );
        $users = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter(
                'city',
                new Coerce(new ListType(new StringType()), $cities),
                'in',
            )],
            columns: ['name'],
            aggregate: 'all',
        );

        $result = $this->execute($users);

        $this->assertSame([], $result->unwrap()->unwrap());
    }

    #[Test]
    public function all_returns_an_empty_list_when_no_results_are_found(): void
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
        $this->assertSame([], $result->unwrap()->unwrap());
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

        $program = $this->expression($source)->compile()->unwrap();

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
    }

    #[Test]
    public function all_declares_a_total_list_of_unknown_values(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            aggregate: 'all',
        );

        $returns = $this->expression($source)->compile()->unwrap()->returns;

        $this->assertInstanceOf(ListType::class, $returns);
        $this->assertInstanceOf(UnknownType::class, $returns->type);
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

        $result = $this->expression($source)->compile();

        $this->assertTrue($result->isErr());
    }

    #[Test]
    public function range_filter_returns_false_when_min_column_missing(): void
    {
        $this->filesystem->write('missing_min.csv', "200,Product\n");
        $source = $this->lookup(
            path: 'missing_min.csv',
            filters: [new RangeFilter(2, 0, new StaticSource(100))],
            aggregate: 'count',
            hasHeader: false,
            schema: self::numbers(2, 0),
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isNone());

        $this->filesystem->delete('missing_min.csv');
    }

    #[Test]
    public function range_filter_returns_false_when_max_column_missing(): void
    {
        $this->filesystem->write('missing_max.csv', "50,Product\n");
        $source = $this->lookup(
            path: 'missing_max.csv',
            filters: [new RangeFilter(0, 2, new StaticSource(100))],
            aggregate: 'count',
            hasHeader: false,
            schema: self::numbers(0, 2),
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isNone());

        $this->filesystem->delete('missing_max.csv');
    }

    #[Test]
    public function range_filters_require_orderable_declared_column_types(): void
    {
        $source = $this->lookup(
            path: 'premium_bands.csv',
            filters: [new RangeFilter('min_turnover', 'max_turnover', new StaticSource(100000))],
        );

        $result = $this->expression($source)->compile();

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('expects Number and Number', $result->unwrapErr()->describe());
    }

    #[Test]
    public function both_range_columns_must_support_their_bound_operation(): void
    {
        $source = $this->lookup(
            path: 'premium_bands.csv',
            filters: [new RangeFilter('min_turnover', 'max_turnover', new StaticSource(100000))],
            schema: [
                'min_turnover' => new NumberType(),
                'max_turnover' => new StringType(),
            ],
        );

        $result = $this->expression($source)->compile();

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('expects Number and Number', $result->unwrapErr()->describe());
    }

    #[Test]
    public function value_filter_returns_error_for_unsupported_operator(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('name', new StaticSource('Alice'), '??unsupported??')],
        );

        $result = $this->expression($source)->compile();

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('Operator [??unsupported??] is not supported', $result->unwrapErr()->describe());
    }

    #[Test]
    public function value_filter_in_operator_requires_a_list_value(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('name', new StaticSource('Alice'), 'in')],
        );

        $result = $this->expression($source)->compile();

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('must be a present list', $result->unwrapErr()->describe());
    }

    #[Test]
    public function value_filter_in_operator_returns_false_when_cell_absent_from_list(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('name', new StaticSource(['Nobody']), 'in')],
            columns: ['name'],
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isNone());
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

        $result = $this->expression($source)->compile();

        $this->assertTrue($result->isErr());
    }

    /**
     * A sorted fixture large enough that the indexed path really bisects
     * (well past the scan's default window). Every tenth code carries a
     * three-row block so the equal-key block spans more than one record.
     */
    private static function sortedCodes(): string
    {
        $lines = ['code,tier,value'];

        for ($i = 0; $i < 2000; $i++) {
            $code = sprintf('P%04d', $i);
            $lines[] = "{$code},silver,{$i}";

            if ($i % 10 === 0) {
                $lines[] = "{$code},gold," . ($i + 1);
                $lines[] = "{$code},silver," . ($i + 2);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /** @return iterable<string, array{string, string|int|null, string}> */
    public static function indexedAggregates(): iterable
    {
        // A duplicated block, a single row, the last row, and a miss — folded
        // by every aggregate shape the scan feeds.
        foreach (['P0150', 'P0777', 'P1999', 'P2500'] as $target) {
            yield "first of {$target}" => ['first', null, $target];
            yield "last of {$target}" => ['last', null, $target];
            yield "count of {$target}" => ['count', null, $target];
            yield "all of {$target}" => ['all', null, $target];
            yield "sum of {$target}" => ['sum', 'value', $target];
        }
    }

    #[Test]
    #[DataProvider('indexedAggregates')]
    public function an_indexed_lookup_computes_exactly_what_streaming_computes(
        string $aggregate,
        string|int|null $aggregateColumn,
        string $target,
    ): void {
        $this->filesystem->write('sorted_codes.csv', self::sortedCodes());

        $streamed = $this->execute($this->lookup(
            path: 'sorted_codes.csv',
            filters: [$this->filter('code', new StaticSource($target))],
            columns: ['value'],
            aggregate: $aggregate,
            aggregateColumn: $aggregateColumn,
        ));
        $indexed = $this->execute($this->lookup(
            path: 'sorted_codes.csv',
            filters: [$this->filter('code', new StaticSource($target))],
            columns: ['value'],
            aggregate: $aggregate,
            aggregateColumn: $aggregateColumn,
            index: 'code',
        ));

        $this->assertTrue($indexed->isOk());
        $this->assertEquals($streamed->unwrap(), $indexed->unwrap());

        $this->filesystem->delete('sorted_codes.csv');
    }

    #[Test]
    public function an_indexed_lookup_finds_a_block_deep_in_the_file(): void
    {
        $this->filesystem->write('sorted_codes.csv', self::sortedCodes());

        $result = $this->execute($this->lookup(
            path: 'sorted_codes.csv',
            filters: [$this->filter('code', new StaticSource('P0150'))],
            columns: ['value'],
            aggregate: 'all',
            index: 'code',
        ));

        $this->assertSame(['150', '151', '152'], $result->unwrap()->unwrap());

        $this->filesystem->delete('sorted_codes.csv');
    }

    #[Test]
    public function an_indexed_lookup_applies_the_remaining_filters_to_the_block(): void
    {
        $this->filesystem->write('sorted_codes.csv', self::sortedCodes());

        // The same lookup with the tier filter on either side of the index
        // filter: the pairing of a filter with its resolved value must not
        // depend on where the index filter sits.
        $tierFirst = $this->execute($this->lookup(
            path: 'sorted_codes.csv',
            filters: [
                $this->filter('tier', new StaticSource('gold')),
                $this->filter('code', new StaticSource('P0150')),
            ],
            columns: ['value'],
            aggregate: 'all',
            index: 'code',
        ));
        $tierLast = $this->execute($this->lookup(
            path: 'sorted_codes.csv',
            filters: [
                $this->filter('code', new StaticSource('P0150')),
                $this->filter('tier', new StaticSource('silver')),
            ],
            columns: ['value'],
            aggregate: 'all',
            index: 'code',
        ));

        $this->assertSame(['151'], $tierFirst->unwrap()->unwrap());
        $this->assertSame(['150', '152'], $tierLast->unwrap()->unwrap());

        $this->filesystem->delete('sorted_codes.csv');
    }

    #[Test]
    public function an_index_without_an_equality_filter_streams_normally(): void
    {
        $this->filesystem->write('sorted_codes.csv', self::sortedCodes());

        $result = $this->execute($this->lookup(
            path: 'sorted_codes.csv',
            filters: [$this->filter('code', new StaticSource(['P0003', 'P0007']), 'in')],
            columns: ['value'],
            aggregate: 'all',
            index: 'code',
        ));

        $this->assertSame(['3', '7'], $result->unwrap()->unwrap());

        $this->filesystem->delete('sorted_codes.csv');
    }

    #[Test]
    public function an_index_on_a_numerically_typed_column_streams_normally(): void
    {
        // A declared Number column compares numerically; byte order cannot
        // navigate that, so the index is ignored and the stream answers.
        $this->filesystem->write('sorted_numbers.csv', "code,value\n0001,one\n0002,two\n0003,three\n");

        $result = $this->execute($this->lookup(
            path: 'sorted_numbers.csv',
            filters: [$this->filter('code', new StaticSource(2))],
            columns: ['value'],
            schema: self::numbers('code'),
            index: 'code',
        ));

        $this->assertSame('two', $result->unwrap()->unwrap());

        $this->filesystem->delete('sorted_numbers.csv');
    }

    #[Test]
    public function a_dynamic_string_filter_value_can_drive_the_index(): void
    {
        // A nested lookup resolves to a present string, which is a perfectly
        // good seek target: dynamic values ride the index too.
        $this->filesystem->write(
            'users_by_city.csv',
            "city,name\nChicago,Diana\nLA,Bob\nLA,Eve\nNYC,Alice\nNYC,Charlie\n",
        );

        $cityOfBob = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('name', new StaticSource('Bob'))],
            columns: ['city'],
        );

        $result = $this->execute($this->lookup(
            path: 'users_by_city.csv',
            filters: [$this->filter(
                'city',
                new Coerce(new OptionType(new StringType()), $cityOfBob),
            )],
            columns: ['name'],
            aggregate: 'all',
            index: 'city',
        ));

        $this->assertSame(['Bob', 'Eve'], $result->unwrap()->unwrap());

        $this->filesystem->delete('users_by_city.csv');
    }

    #[Test]
    public function an_absent_dynamic_filter_value_returns_none_with_an_index(): void
    {
        // The nested lookup resolves to no value at all — not a string — so
        // the seek declines and the stream answers: no row matches.
        $noneSource = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('name', new StaticSource('NonExistent'))],
            columns: ['city'],
        );

        $result = $this->execute($this->lookup(
            path: 'users.csv',
            filters: [$this->filter(
                'city',
                new Coerce(new OptionType(new StringType()), $noneSource),
            )],
            columns: ['name'],
            index: 'city',
        ));

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isNone());
    }

    #[Test]
    public function an_index_left_untargeted_by_any_filter_streams_normally(): void
    {
        $this->filesystem->write('sorted_codes.csv', self::sortedCodes());

        $result = $this->execute($this->lookup(
            path: 'sorted_codes.csv',
            filters: [$this->filter('tier', new StaticSource('gold'))],
            columns: ['value'],
            index: 'code',
        ));

        $this->assertSame('1', $result->unwrap()->unwrap());

        $this->filesystem->delete('sorted_codes.csv');
    }

    #[Test]
    public function an_index_column_absent_from_the_file_matches_nothing(): void
    {
        $this->filesystem->write('sorted_codes.csv', self::sortedCodes());

        $result = $this->execute($this->lookup(
            path: 'sorted_codes.csv',
            filters: [$this->filter('postcode', new StaticSource('P0001'))],
            columns: ['value'],
            index: 'postcode',
        ));

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isNone());

        $this->filesystem->delete('sorted_codes.csv');
    }

    #[Test]
    public function a_named_index_on_a_headerless_file_matches_nothing(): void
    {
        $result = $this->execute($this->lookup(
            path: 'no_header.csv',
            filters: [$this->filter('code', new StaticSource('2'))],
            columns: [1],
            hasHeader: false,
            index: 'code',
        ));

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isNone());
    }

    #[Test]
    public function duplicated_header_names_fail_identically_with_an_index(): void
    {
        $this->filesystem->write('duplicated_header.csv', "code,code\na,1\nb,2\n");

        $streamed = $this->execute($this->lookup(
            path: 'duplicated_header.csv',
            filters: [$this->filter('code', new StaticSource('a'))],
        ));
        $indexed = $this->execute($this->lookup(
            path: 'duplicated_header.csv',
            filters: [$this->filter('code', new StaticSource('a'))],
            index: 'code',
        ));

        $this->assertTrue($streamed->isErr());
        $this->assertTrue($indexed->isErr());
        $this->assertSame($streamed->unwrapErr()->getMessage(), $indexed->unwrapErr()->getMessage());

        $this->filesystem->delete('duplicated_header.csv');
    }

    #[Test]
    public function an_indexed_lookup_declines_a_non_seekable_stream(): void
    {
        // The package requires seekable streams either way — League refuses a
        // socket — but the indexed path must decline before probing, so the
        // error is the reader's own, not a garbled seek.
        $fixture = __DIR__ . '/Fixtures/users.csv';

        $sockets = static function () use ($fixture) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            assert($pair !== false);
            [$reader, $writer] = $pair;
            fwrite($writer, (string) file_get_contents($fixture));
            fclose($writer);

            return $reader;
        };

        $streamedFilesystem = $this->createStub(FilesystemOperator::class);
        $streamedFilesystem->method('readStream')->willReturnCallback($sockets);
        $indexedFilesystem = $this->createStub(FilesystemOperator::class);
        $indexedFilesystem->method('readStream')->willReturnCallback($sockets);

        $filters = [$this->filter('name', new StaticSource('Alice'))];
        $streamed = $this->expression($this->lookup('users.csv', $filters), $streamedFilesystem)
            ->compile()->unwrap()();
        $indexed = $this->expression($this->lookup('users.csv', $filters, index: 'name'), $indexedFilesystem)
            ->compile()->unwrap()();

        $this->assertTrue($streamed->isErr());
        $this->assertTrue($indexed->isErr());
        $this->assertSame($streamed->unwrapErr()->getMessage(), $indexed->unwrapErr()->getMessage());
    }

    #[Test]
    public function the_lookup_reads_through_an_injected_reader(): void
    {
        // The extension only ever asks its LookupSourceReader for records —
        // a host can swap the whole reading strategy, and the resolved index
        // target reaches it.
        $reader = new class implements LookupSourceReader {
            public ?string $value = null;

            public function findRecord(LookupSource $source, ?string $value, ?\Closure $scanned = null): iterable
            {
                $this->value = $value;

                yield ['name' => 'Alice', 'age' => '99'];
            }
        };

        $dialect = Dialect::core()->with(new LookupExtension($this->filesystem, $reader));
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('name', new StaticSource('Alice'))],
            columns: ['age'],
            index: 'name',
        );

        $result = (new Expression($source, dialect: $dialect))->compile()->unwrap()();

        $this->assertSame('99', $result->unwrap()->unwrap());
        $this->assertSame('Alice', $reader->value);
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
        );

        $result = $this->expression($source, $mockFilesystem)->compile()->unwrap()();

        $this->assertTrue($result->isErr());
        $error = $result->unwrapErr();
        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertStringContainsString('Could not open file', $error->getMessage());
        $this->assertStringContainsString('test.csv', $error->getMessage());
    }
}
