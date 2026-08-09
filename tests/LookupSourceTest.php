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
use Superscript\Axiom\Lookup\Column;
use Superscript\Axiom\Lookup\DelimitedTable;
use Superscript\Axiom\Lookup\LookupExtension;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Support\Filters\CompiledFilter;
use Superscript\Axiom\Lookup\Support\Filters\Filter;
use Superscript\Axiom\Lookup\Support\Filters\RangeFilter;
use Superscript\Axiom\Lookup\Support\Filters\ResolvedFilter;
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Lookup\Support\Results\AllRows;
use Superscript\Axiom\Lookup\Support\Results\AverageColumn;
use Superscript\Axiom\Lookup\Support\Results\CountRows;
use Superscript\Axiom\Lookup\Support\Results\FirstRow;
use Superscript\Axiom\Lookup\Support\Results\LastRow;
use Superscript\Axiom\Lookup\Support\Results\LookupResultKind;
use Superscript\Axiom\Lookup\Support\Results\MaximumRow;
use Superscript\Axiom\Lookup\Support\Results\MinimumRow;
use Superscript\Axiom\Lookup\Support\Results\NumericResult;
use Superscript\Axiom\Lookup\Support\Results\ProjectedResult;
use Superscript\Axiom\Lookup\Support\Results\RecordProjection;
use Superscript\Axiom\Lookup\Support\Results\SumColumn;
use Superscript\Axiom\Lookup\Support\Results\ValueProjection;
use Superscript\Axiom\Operators\Operator;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\Coerce;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\RecordType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
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
#[UsesClass(Column::class)]
#[UsesClass(DelimitedTable::class)]
#[UsesClass(AllRows::class)]
#[UsesClass(AverageColumn::class)]
#[UsesClass(\Superscript\Axiom\Lookup\Support\Results\CompiledLookupResult::class)]
#[UsesClass(CountRows::class)]
#[UsesClass(FirstRow::class)]
#[UsesClass(LastRow::class)]
#[UsesClass(LookupResultKind::class)]
#[UsesClass(MaximumRow::class)]
#[UsesClass(MinimumRow::class)]
#[UsesClass(NumericResult::class)]
#[UsesClass(ProjectedResult::class)]
#[UsesClass(RecordProjection::class)]
#[UsesClass(SumColumn::class)]
#[UsesClass(ValueProjection::class)]
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
        string $resultKind = 'first',
        string|int|null $resultColumn = null,
        string $delimiter = ',',
        bool $hasHeader = true,
        array $schema = [],
    ): LookupSource {
        foreach ($filters as $filter) {
            if ($filter instanceof ValueFilter) {
                $schema[$filter->column] ??= new StringType();
            }

            if ($filter instanceof RangeFilter) {
                $schema[$filter->minColumn] ??= new StringType();
                $schema[$filter->maxColumn] ??= new StringType();
            }
        }

        if ($resultColumn !== null) {
            $schema[$resultColumn] ??= in_array($resultKind, ['min', 'max', 'sum', 'avg'], strict: true)
                ? new NumberType()
                : new StringType();
        }

        foreach ($columns as $column) {
            $schema[$column] ??= new StringType();
        }

        $projection = function () use ($columns, $schema): ValueProjection|RecordProjection {
            if (count($columns) === 1) {
                return new ValueProjection($columns[0]);
            }

            $projected = $columns === [] ? array_keys($schema) : $columns;

            if ($projected === []) {
                return new ValueProjection('__missing');
            }

            return new RecordProjection(array_combine(array_map(strval(...), $projected), $projected));
        };

        $result = match ($resultKind) {
            'first' => new ProjectedResult(new FirstRow(), $projection()),
            'last' => new ProjectedResult(new LastRow(), $projection()),
            'all' => new ProjectedResult(new AllRows(), $projection()),
            'min' => new ProjectedResult(new MinimumRow($resultColumn ?? '__missing'), $projection()),
            'max' => new ProjectedResult(new MaximumRow($resultColumn ?? '__missing'), $projection()),
            'count' => new NumericResult(new CountRows()),
            'sum' => new NumericResult(new SumColumn($resultColumn ?? '__missing')),
            'avg' => new NumericResult(new AverageColumn($resultColumn ?? '__missing')),
            default => throw new RuntimeException("Unknown test result kind [{$resultKind}]."),
        };

        return new LookupSource(
            table: new DelimitedTable(
                path: $path,
                columns: array_map(
                    fn(string|int $identity, Type $type): Column => new Column($identity, $type),
                    array_keys($schema),
                    array_values($schema),
                ),
                delimiter: $delimiter,
                hasHeader: $hasHeader,
            ),
            result: $result,
            filters: $filters,
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
        Source $source,
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
            resultKind: 'first',
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
            resultKind: 'last',
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
            resultKind: 'min',
            resultColumn: 'salary',
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
            resultKind: 'max',
            resultColumn: 'salary',
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
    public function a_record_projection_can_alias_declared_columns(): void
    {
        $source = new LookupSource(
            table: new DelimitedTable('users.csv', [
                new Column('name', new StringType()),
                new Column('age', new NumberType()),
            ]),
            result: new ProjectedResult(
                new FirstRow(),
                new RecordProjection(['user' => 'name', 'years' => 'age']),
            ),
            filters: [$this->filter('name', new StaticSource('Alice'))],
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $row = $result->unwrap()->unwrap();
        $this->assertIsArray($row);
        $this->assertSame(['user' => 'Alice', 'years' => 30], $row);
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
            resultKind: 'min',
            resultColumn: 'price',
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
            resultKind: 'max',
            resultColumn: 'price',
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
    public function minimum_row_requires_a_declared_ordering_column(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('city', new StaticSource('NYC'))],
            columns: ['salary'],
            resultKind: 'min',
        );

        $result = $this->expression($source)->compile();

        $this->assertTrue($result->isErr());
    }

    #[Test]
    public function maximum_row_requires_a_declared_ordering_column(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('city', new StaticSource('NYC'))],
            columns: ['salary'],
            resultKind: 'max',
        );

        $result = $this->expression($source)->compile();

        $this->assertTrue($result->isErr());
    }

    #[Test]
    public function it_returns_count_of_matching_rows(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('city', new StaticSource('NYC'))],
            resultKind: 'count',
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
            resultKind: 'sum',
            resultColumn: 'salary',
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
            resultKind: 'avg',
            resultColumn: 'salary',
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertEquals(80000.0, $result->unwrap()->unwrap()); // (75000 + 85000) / 2
    }

    #[Test]
    public function sum_requires_a_declared_numeric_column(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('city', new StaticSource('NYC'))],
            resultKind: 'sum',
        );

        $result = $this->expression($source)->compile();

        $this->assertTrue($result->isErr());
    }

    #[Test]
    public function average_requires_a_declared_numeric_column(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('city', new StaticSource('NYC'))],
            resultKind: 'avg',
        );

        $result = $this->expression($source)->compile();

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
        $this->filesystem->write('regional_bands_runtime.csv', $csvContent);

        $source = $this->lookup(
            path: 'regional_bands_runtime.csv',
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
        $this->filesystem->delete('regional_bands_runtime.csv');
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
        $this->filesystem->write('raw_strings_runtime.csv', "key,label\n,blank\nnull,literal-null\n");

        $blank = $this->execute($this->lookup(
            path: 'raw_strings_runtime.csv',
            filters: [$this->filter('key', new StaticSource(''))],
            columns: ['label'],
        ));
        $literalNull = $this->execute($this->lookup(
            path: 'raw_strings_runtime.csv',
            filters: [$this->filter('key', new StaticSource('null'))],
            columns: ['label'],
        ));

        $this->assertSame('blank', $blank->unwrap()->unwrap());
        $this->assertSame('literal-null', $literalNull->unwrap()->unwrap());

        $this->filesystem->delete('raw_strings_runtime.csv');
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
            resultKind: 'count',
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
            resultKind: 'count',
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
            resultKind: 'count',
            hasHeader: false,
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isErr());

        $this->filesystem->delete('missing_value.csv');
    }

    #[Test]
    public function range_filters_do_not_match_values_below_the_minimum(): void
    {
        $this->filesystem->write('below_minimum_runtime.csv', "minimum,maximum\n100,200\n");
        $source = $this->lookup(
            path: 'below_minimum_runtime.csv',
            filters: [new RangeFilter('minimum', 'maximum', new StaticSource(99))],
            resultKind: 'count',
            schema: self::numbers('minimum', 'maximum'),
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertSame(0, $result->unwrap()->unwrap());

        $this->filesystem->delete('below_minimum_runtime.csv');
    }

    #[Test]
    public function it_returns_none_for_avg_when_count_is_zero(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('name', new StaticSource('NonExistentPerson'))],
            columns: ['age'],
            resultKind: 'avg',
            resultColumn: 'age',
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
            resultKind: 'sum',
            resultColumn: 'age',
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
            resultKind: 'sum',
            resultColumn: 'value',
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
            resultKind: 'all',
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
            resultKind: 'all',
        );
        $users = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter(
                'city',
                new Coerce(new ListType(new StringType()), $cities),
                'in',
            )],
            columns: ['name'],
            resultKind: 'all',
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
            resultKind: 'all',
        );
        $users = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter(
                'city',
                new Coerce(new ListType(new StringType()), $cities),
                'in',
            )],
            columns: ['name'],
            resultKind: 'all',
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
            resultKind: 'all',
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertSame([], $result->unwrap()->unwrap());
    }

    #[Test]
    public function count_is_total_while_sum_and_average_are_optional_numbers(): void
    {
        $count = $this->expression($this->lookup(path: 'users.csv', resultKind: 'count'))
            ->compile()->unwrap()->returns;
        $sum = $this->expression($this->lookup(
            path: 'users.csv',
            resultKind: 'sum',
            resultColumn: 'salary',
        ))->compile()->unwrap()->returns;
        $average = $this->expression($this->lookup(
            path: 'users.csv',
            resultKind: 'avg',
            resultColumn: 'salary',
        ))->compile()->unwrap()->returns;

        $this->assertInstanceOf(NumberType::class, $count);
        $this->assertInstanceOf(OptionType::class, $sum);
        $this->assertInstanceOf(NumberType::class, $sum->inner);
        $this->assertInstanceOf(OptionType::class, $average);
        $this->assertInstanceOf(NumberType::class, $average->inner);
    }

    #[Test]
    public function an_undeclared_projected_column_is_rejected(): void
    {
        $lookup = new LookupSource(
            table: new DelimitedTable('users.csv', [new Column('name', new StringType())]),
            result: new ProjectedResult(new AllRows(), new ValueProjection('salary')),
        );

        $result = $this->expression($lookup)->compile();

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('not declared', $result->unwrapErr()->describe());
    }

    #[Test]
    public function a_list_of_a_declared_column_can_be_compared_with_intersects(): void
    {
        $lookup = $this->lookup(
            path: 'industries.csv',
            filters: [$this->filter('Trade', new StaticSource('Access Control Manufacturing'))],
            columns: ['Manual Work Question'],
            resultKind: 'all',
            schema: ['Manual Work Question' => new StringType()],
        );

        $program = $this->expression(
            new InfixExpression($lookup, 'intersects', new StaticSource(['TRUE'])),
        )->compile()->unwrap();

        $this->assertTrue($program()->unwrap()->unwrap());
    }

    #[Test]
    public function all_declares_a_list_of_the_projected_columns_declared_type(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            columns: ['salary'],
            resultKind: 'all',
            schema: self::numbers('salary'),
        );

        $returns = $this->expression($source)->compile()->unwrap()->returns;

        $this->assertInstanceOf(ListType::class, $returns);
        $this->assertInstanceOf(NumberType::class, $returns->type);
    }

    #[Test]
    #[DataProvider('rowSelections')]
    public function row_selections_declare_the_projected_columns_declared_type(string $resultKind): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            columns: ['salary'],
            resultKind: $resultKind,
            resultColumn: 'salary',
            schema: self::numbers('salary'),
        );

        $returns = $this->expression($source)->compile()->unwrap()->returns;

        $this->assertInstanceOf(OptionType::class, $returns);
        $this->assertInstanceOf(NumberType::class, $returns->inner);
    }

    /** @return iterable<string, array{string}> */
    public static function rowSelections(): iterable
    {
        yield 'first' => ['first'];
        yield 'last' => ['last'];
        yield 'min' => ['min'];
        yield 'max' => ['max'];
    }

    #[Test]
    public function an_undeclared_filter_column_is_rejected(): void
    {
        $source = new LookupSource(
            table: new DelimitedTable('users.csv', [new Column('name', new StringType())]),
            result: new ProjectedResult(new AllRows(), new ValueProjection('name')),
            filters: [$this->filter('city', new StaticSource('NYC'))],
        );

        $result = $this->expression($source)->compile();

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('not declared', $result->unwrapErr()->describe());
    }

    #[Test]
    public function projecting_several_declared_columns_has_an_exact_record_type(): void
    {
        // Several columns extract as a column-keyed array, not a cell, so no
        // single column's declaration describes the result.
        $source = $this->lookup(
            path: 'users.csv',
            columns: ['salary', 'age'],
            resultKind: 'all',
            schema: self::numbers('salary', 'age'),
        );

        $returns = $this->expression($source)->compile()->unwrap()->returns;

        $this->assertInstanceOf(ListType::class, $returns);
        $this->assertInstanceOf(RecordType::class, $returns->type);
        $this->assertSame(['salary', 'age'], array_keys($returns->type->fields));
        $this->assertInstanceOf(NumberType::class, $returns->type->fields['salary']);
        $this->assertInstanceOf(NumberType::class, $returns->type->fields['age']);
    }

    #[Test]
    #[DataProvider('numericResults')]
    public function numeric_results_ignore_the_projected_columns_declared_type(
        string $resultKind,
        ?string $resultColumn,
        int|float $expected,
    ): void {
        $source = $this->lookup(
            path: 'users.csv',
            columns: ['name'],
            resultKind: $resultKind,
            resultColumn: $resultColumn,
            schema: ['name' => new StringType()],
        );

        $program = $this->expression($source)->compile()->unwrap();

        if ($resultKind === 'count') {
            $this->assertInstanceOf(NumberType::class, $program->returns);
        } else {
            $this->assertInstanceOf(OptionType::class, $program->returns);
            $this->assertInstanceOf(NumberType::class, $program->returns->inner);
        }
        $this->assertSame($expected, $program()->unwrap()->unwrap());
    }

    /** @return iterable<string, array{string, ?string, int|float}> */
    public static function numericResults(): iterable
    {
        yield 'count' => ['count', null, 5];
        yield 'sum' => ['sum', 'salary', 375000.0];
        yield 'avg' => ['avg', 'salary', 75000.0];
    }

    #[Test]
    public function a_declared_column_projects_cells_of_that_type(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('name', new StaticSource('Alice'))],
            columns: ['salary'],
            schema: self::numbers('salary'),
        );

        $result = $this->execute($source);

        $this->assertSame(75000, $result->unwrap()->unwrap());
    }

    #[Test]
    public function a_declared_column_projects_every_cell_of_a_total_list(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('city', new StaticSource('NYC'))],
            columns: ['salary'],
            resultKind: 'all',
            schema: self::numbers('salary'),
        );

        $result = $this->execute($source);

        $this->assertSame([75000, 85000], $result->unwrap()->unwrap());
    }

    #[Test]
    public function a_declared_string_column_projects_its_cells_as_written(): void
    {
        // '' and 'null' are lenient readings of absence, but they are the raw
        // cells this file holds.
        $this->filesystem->write('raw_cells.csv', "key,label\n,blank\nnull,literal-null\n");
        $source = $this->lookup(
            path: 'raw_cells.csv',
            columns: ['key'],
            resultKind: 'all',
            schema: ['key' => new StringType()],
        );

        $result = $this->execute($source);

        $this->assertSame(['', 'null'], $result->unwrap()->unwrap());

        $this->filesystem->delete('raw_cells.csv');
    }

    #[Test]
    public function a_cell_that_breaks_its_columns_declaration_fails_the_lookup(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('city', new StaticSource('NYC'))],
            columns: ['name'],
            schema: self::numbers('name'),
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isErr());
        $this->assertSame(
            'Column [name] of [users.csv] is declared Number, but a matching row holds [\'Alice\'].',
            $result->unwrapErr()->getMessage(),
        );
    }

    #[Test]
    public function an_absent_cell_of_a_declared_column_cannot_enter_a_total_list(): void
    {
        $this->filesystem->write('empty_score.csv', "id,score\n1,\n");
        $source = $this->lookup(
            path: 'empty_score.csv',
            columns: ['score'],
            resultKind: 'all',
            schema: self::numbers('score'),
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isErr());
        $this->assertSame(
            'Column [score] of [empty_score.csv] is declared Number, but a matching row has no value for it.',
            $result->unwrapErr()->getMessage(),
        );

        $this->filesystem->delete('empty_score.csv');
    }

    #[Test]
    public function an_absent_required_cell_fails_a_single_value_projection(): void
    {
        $this->filesystem->write('empty_score.csv', "id,score\n1,\n");
        $source = $this->lookup(
            path: 'empty_score.csv',
            columns: ['score'],
            schema: self::numbers('score'),
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('matching row has no value', $result->unwrapErr()->getMessage());

        $this->filesystem->delete('empty_score.csv');
    }

    #[Test]
    public function a_lookup_matching_nothing_is_absent_even_with_a_declared_column(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('name', new StaticSource('NonExistent'))],
            columns: ['salary'],
            schema: self::numbers('salary'),
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isNone());
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
    public function a_missing_required_minimum_fails_the_lookup(): void
    {
        $this->filesystem->write('missing_min_runtime.csv', "200,Product\n");
        $source = $this->lookup(
            path: 'missing_min_runtime.csv',
            filters: [new RangeFilter(2, 0, new StaticSource(100))],
            resultKind: 'count',
            hasHeader: false,
            schema: self::numbers(2, 0),
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isErr());

        $this->filesystem->delete('missing_min_runtime.csv');
    }

    #[Test]
    public function a_missing_required_maximum_fails_the_lookup(): void
    {
        $this->filesystem->write('missing_max.csv', "50,Product\n");
        $source = $this->lookup(
            path: 'missing_max.csv',
            filters: [new RangeFilter(0, 2, new StaticSource(100))],
            resultKind: 'count',
            hasHeader: false,
            schema: self::numbers(0, 2),
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isErr());

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
    public function first_row_stops_processing_after_first_match(): void
    {
        $source = $this->lookup(
            path: 'users.csv',
            filters: [$this->filter('city', new StaticSource('NYC'))],
            columns: ['name'],
            resultKind: 'first',
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
        $this->filesystem->write('stream_error.csv', "value\nnot-a-number\n");
        $source = new LookupSource(
            table: new DelimitedTable('stream_error.csv', [new Column('value', new NumberType())]),
            result: new ProjectedResult(new FirstRow(), new ValueProjection('value')),
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString("holds ['not-a-number']", $result->unwrapErr()->getMessage());

        // A leaked local stream would keep this deletion from succeeding on Windows.
        $this->filesystem->delete('stream_error.csv');
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

    #[Test]
    public function numeric_folds_require_numeric_declarations(): void
    {
        $source = new LookupSource(
            table: new DelimitedTable('users.csv', [new Column('name', new StringType())]),
            result: new NumericResult(new SumColumn('name')),
        );

        $result = $this->expression($source)->compile();

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('must be Number', $result->unwrapErr()->describe());
        $this->assertStringContainsString('String is not assignable to Number', $result->unwrapErr()->describe());
    }

    #[Test]
    public function an_absent_optional_value_filter_cell_does_not_match(): void
    {
        $this->filesystem->write('optional_filter.csv', "tag,other\n,placeholder\nx,placeholder\n");
        $source = new LookupSource(
            table: new DelimitedTable('optional_filter.csv', [
                new Column('tag', new OptionType(new StringType())),
            ]),
            result: new NumericResult(new CountRows()),
            filters: [$this->filter('tag', new StaticSource('x'))],
        );

        $result = $this->execute($source);

        $this->assertSame(1, $result->unwrap()->unwrap());
        $this->filesystem->delete('optional_filter.csv');
    }

    #[Test]
    public function a_range_with_an_absent_optional_bound_does_not_match(): void
    {
        $this->filesystem->write('optional_range.csv', "minimum,maximum\n,200\n100,200\n");
        $optionalNumber = new OptionType(new NumberType());
        $source = new LookupSource(
            table: new DelimitedTable('optional_range.csv', [
                new Column('minimum', $optionalNumber),
                new Column('maximum', $optionalNumber),
            ]),
            result: new NumericResult(new CountRows()),
            filters: [new RangeFilter('minimum', 'maximum', new StaticSource(150))],
        );

        $result = $this->execute($source);

        $this->assertSame(1, $result->unwrap()->unwrap());
        $this->filesystem->delete('optional_range.csv');
    }

    #[Test]
    public function every_named_declaration_must_exist_in_the_header(): void
    {
        $source = new LookupSource(
            table: new DelimitedTable('users.csv', [
                new Column('name', new StringType()),
                new Column('missing', new StringType()),
            ]),
            result: new ProjectedResult(new FirstRow(), new ValueProjection('name')),
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('header is missing', $result->unwrapErr()->getMessage());
    }

    #[Test]
    public function row_ordering_skips_absent_optional_values(): void
    {
        $this->filesystem->write('optional_order.csv', "id,score\nmissing,\npresent,5\n");
        $source = new LookupSource(
            table: new DelimitedTable('optional_order.csv', [
                new Column('id', new StringType()),
                new Column('score', new OptionType(new NumberType())),
            ]),
            result: new ProjectedResult(new MinimumRow('score'), new ValueProjection('id')),
        );

        $result = $this->execute($source);

        $this->assertSame('present', $result->unwrap()->unwrap());
        $this->filesystem->delete('optional_order.csv');
    }

    #[Test]
    public function invalid_ordering_values_fail_while_selecting_a_row(): void
    {
        $this->filesystem->write('invalid_order.csv', "id,score\ninvalid,not-a-number\n");
        $source = new LookupSource(
            table: new DelimitedTable('invalid_order.csv', [
                new Column('id', new StringType()),
                new Column('score', new NumberType()),
            ]),
            result: new ProjectedResult(new MinimumRow('score'), new ValueProjection('id')),
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isErr());
        $this->filesystem->delete('invalid_order.csv');
    }

    #[Test]
    public function numeric_folds_skip_absent_optional_values(): void
    {
        $this->filesystem->write('optional_sum.csv', "value,other\n,placeholder\n5,placeholder\n");
        $source = new LookupSource(
            table: new DelimitedTable('optional_sum.csv', [
                new Column('value', new OptionType(new NumberType())),
            ]),
            result: new NumericResult(new SumColumn('value')),
        );

        $result = $this->execute($source);

        $this->assertSame(5.0, $result->unwrap()->unwrap());
        $this->filesystem->delete('optional_sum.csv');
    }

    #[Test]
    public function numeric_folds_guard_the_admitted_runtime_value(): void
    {
        $dishonestNumber = new class extends NumberType {
            public function coerce(mixed $value): Result
            {
                return \Superscript\Monads\Result\Ok(\Superscript\Monads\Option\Some('not-a-number'));
            }
        };
        $source = new LookupSource(
            table: new DelimitedTable('users.csv', [new Column('salary', $dishonestNumber)]),
            result: new NumericResult(new SumColumn('salary')),
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('admitted a non-numeric value', $result->unwrapErr()->getMessage());
    }

    #[Test]
    public function a_record_projection_stops_at_an_invalid_declared_field(): void
    {
        $source = new LookupSource(
            table: new DelimitedTable('users.csv', [
                new Column('name', new StringType()),
                new Column('city', new NumberType()),
            ]),
            result: new ProjectedResult(
                new FirstRow(),
                new RecordProjection(['name' => 'name', 'city' => 'city']),
            ),
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString("Column [city]", $result->unwrapErr()->getMessage());
    }

    #[Test]
    public function a_single_optional_projection_is_not_wrapped_twice(): void
    {
        $optionalName = new OptionType(new StringType());
        $source = new LookupSource(
            table: new DelimitedTable('users.csv', [new Column('name', $optionalName)]),
            result: new ProjectedResult(new FirstRow(), new ValueProjection('name')),
        );

        $returns = $this->expression($source)->compile()->unwrap()->returns;

        $this->assertSame($optionalName, $returns);
    }

    #[Test]
    public function a_physically_missing_optional_position_is_absent(): void
    {
        $this->filesystem->write('optional_position.csv', "id,present\n");
        $source = new LookupSource(
            table: new DelimitedTable(
                'optional_position.csv',
                [new Column(2, new OptionType(new NumberType()))],
                hasHeader: false,
            ),
            result: new ProjectedResult(new FirstRow(), new ValueProjection(2)),
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isNone());
        $this->filesystem->delete('optional_position.csv');
    }

    #[Test]
    public function a_physically_missing_required_position_fails_before_coercion(): void
    {
        $this->filesystem->write('required_position.csv', "id,present\n");
        $dishonestNumber = new class extends NumberType {
            public function coerce(mixed $value): Result
            {
                return \Superscript\Monads\Result\Ok(\Superscript\Monads\Option\Some(7));
            }
        };
        $source = new LookupSource(
            table: new DelimitedTable(
                'required_position.csv',
                [new Column(2, $dishonestNumber)],
                hasHeader: false,
            ),
            result: new ProjectedResult(new FirstRow(), new ValueProjection(2)),
        );

        $result = $this->execute($source);

        $this->assertTrue($result->isErr());
        $this->filesystem->delete('required_position.csv');
    }

    #[Test]
    public function minimum_row_keeps_scanning_after_replacements_and_non_replacements(): void
    {
        $this->filesystem->write('minimum_replacements.csv', "id,score\nfirst,3\nsecond,2\nthird,1\n");
        $this->filesystem->write('minimum_non_replacements.csv', "id,score\nfirst,1\nsecond,3\nthird,0\n");

        $lookup = fn(string $path): LookupSource => new LookupSource(
            table: new DelimitedTable($path, [
                new Column('id', new StringType()),
                new Column('score', new NumberType()),
            ]),
            result: new ProjectedResult(new MinimumRow('score'), new ValueProjection('id')),
        );

        $this->assertSame('third', $this->execute($lookup('minimum_replacements.csv'))->unwrap()->unwrap());
        $this->assertSame('third', $this->execute($lookup('minimum_non_replacements.csv'))->unwrap()->unwrap());

        $this->filesystem->delete('minimum_replacements.csv');
        $this->filesystem->delete('minimum_non_replacements.csv');
    }
}
