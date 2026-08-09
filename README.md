# Axiom Lookup

A streaming Axiom source for typed lookups over CSV, TSV, and other delimited files.

## Features

- Streams rows with constant memory for first, last, minimum, maximum, count, sum, and average results.
- Separates file structure (`DelimitedTable`) from result construction (`ProjectedResult` or `NumericResult`).
- Requires a concrete type declaration for every referenced column.
- Supports scalar and exact-record projections, including output-field aliases.
- Compiles filter and ordering operations from the composed Axiom dialect.
- Treats missing required cells as errors and optional cells according to `OptionType`.
- Uses Flysystem, so the same lookup descriptions work with local, cloud, and in-memory storage.
- Keeps lookup descriptions serializable: the filesystem is injected into `LookupExtension`, not stored in `LookupSource`.

## Installation

```bash
composer require gosuperscript/axiom-lookup
```

## Quick start

```php
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Lookup\Column;
use Superscript\Axiom\Lookup\DelimitedTable;
use Superscript\Axiom\Lookup\LookupExtension;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Lookup\Support\Results\FirstRow;
use Superscript\Axiom\Lookup\Support\Results\ProjectedResult;
use Superscript\Axiom\Lookup\Support\Results\RecordProjection;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;

$filesystem = new Filesystem(new LocalFilesystemAdapter('/path/to/data'));
$dialect = Dialect::core()->with(new LookupExtension($filesystem));

$lookup = new LookupSource(
    table: new DelimitedTable(
        path: 'products.csv',
        columns: [
            new Column('sku', new StringType()),
            new Column('category', new StringType()),
            new Column('price', new NumberType()),
        ],
    ),
    result: new ProjectedResult(
        rows: new FirstRow(),
        projection: new RecordProjection([
            'product' => 'sku',
            'unit_price' => 'price',
        ]),
    ),
    filters: [
        new ValueFilter('category', new StaticSource('books')),
    ],
);

$program = (new Expression($lookup, dialect: $dialect))->compile()->unwrap();
$result = $program();
```

The projected record has the exact type `{product: String, unit_price: Number}`. Because `FirstRow` may find no matching row, the lookup declares an optional record result.

## The model

### Delimited tables and column declarations

`DelimitedTable` owns the path, delimiter, header mode, and its partial list of column declarations. A declaration pairs a string header or integer position with an Axiom `Type`:

```php
$table = new DelimitedTable(
    path: 'rates.tsv',
    columns: [
        new Column('region', new StringType()),
        new Column('minimum', new NumberType()),
        new Column('maximum', new NumberType()),
        new Column('rate', new NumberType()),
    ],
    delimiter: "\t",
);
```

Every column used by a filter, projection, row ordering, sum, or average must be declared. Undeclared references are compile errors. For a table with headers, every declared header must also exist when the file is opened. A headerless table uses integer identities instead:

```php
new DelimitedTable(
    path: 'records.csv',
    columns: [
        new Column(0, new StringType()),
        new Column(2, new NumberType()),
    ],
    hasHeader: false,
);
```

Declarations are partial: columns that a lookup never references do not need declarations.

### Projected results

A projected result combines a row selection with an explicit projection.

Available row selections are:

- `FirstRow`
- `LastRow`
- `AllRows`
- `MinimumRow($column)`
- `MaximumRow($column)`

`ValueProjection` produces one typed scalar:

```php
result: new ProjectedResult(
    rows: new AllRows(),
    projection: new ValueProjection('city'),
)
```

This declares `List<String>` when `city` is declared `String`.

`RecordProjection` produces an exact record and maps output names to source identities:

```php
result: new ProjectedResult(
    rows: new FirstRow(),
    projection: new RecordProjection([
        'name' => 'display_name',
        'score' => 'ranking_score',
    ]),
)
```

There is no implicit whole-row result and no shape inferred from an empty or multi-item `columns` argument. The projection always states the result shape.

### Numeric results

Numeric results fold matching rows without a projection:

```php
use Superscript\Axiom\Lookup\Support\Results\AverageColumn;
use Superscript\Axiom\Lookup\Support\Results\CountRows;
use Superscript\Axiom\Lookup\Support\Results\NumericResult;
use Superscript\Axiom\Lookup\Support\Results\SumColumn;

new NumericResult(new CountRows());
new NumericResult(new SumColumn('price'));
new NumericResult(new AverageColumn('price'));
```

`CountRows` is total and returns `0` when no rows match. `SumColumn` and `AverageColumn` require a `Number` or `Option<Number>` declaration and return an optional number because no present values may exist.

### Result discovery

`LookupResultKind` is metadata for user interfaces and serializers. Runtime behavior is represented by the result objects above; the enum is not a factory.

```php
use Superscript\Axiom\Lookup\Support\Results\LookupResultKind;

LookupResultKind::names();
// ['first', 'last', 'all', 'min', 'max', 'count', 'sum', 'avg']

LookupResultKind::Sum->family();          // LookupResultFamily::Numeric
LookupResultKind::Sum->requiresColumn();  // true
LookupResultKind::Count->requiresColumn(); // false
```

## Filters

`ValueFilter` compares a declared column with a compiled Axiom source:

```php
new ValueFilter('status', new StaticSource('active'));
new ValueFilter('score', new StaticSource(80), '>=');
```

`RangeFilter` performs a `[minimum, maximum)` comparison:

```php
use Superscript\Axiom\Lookup\Support\Filters\RangeFilter;
use Superscript\Axiom\Sources\SymbolSource;

new RangeFilter('minimum', 'maximum', new SymbolSource('amount'));
```

Filter values may be static values, symbols, or nested lookups. Operators are resolved from the composed dialect during compilation and must return `Boolean`.

## Optional columns

Optionality belongs to the declaration:

```php
use Superscript\Axiom\Types\OptionType;

new Column('nickname', new OptionType(new StringType()));
```

- A missing or empty required value fails the lookup.
- An absent optional filter or range value does not match.
- Minimum and maximum row selections skip absent optional ordering values.
- Sum and average skip absent optional numeric values.
- A scalar single-row projection flattens “no matching row” and “absent projected value” into one optional value.
- A record projection preserves the distinction: the record itself may be absent, while an optional field is present as `null` inside a matched record.

## Storage backends

The filesystem is selected when composing the dialect:

```php
$dialect = Dialect::core()->with(new LookupExtension($filesystem));
```

Any Flysystem `FilesystemOperator` can be used, including local files, S3-compatible storage, Azure Blob Storage, SFTP, and in-memory adapters.

## Testing

```bash
composer test:types
composer test:unit
composer test:infection
composer test
```

## Benchmarking

```bash
composer bench
composer bench:result
composer bench:memory
```

The streaming implementation retains constant memory for every result except `AllRows`, which necessarily materializes all projected rows.

## Requirements

- PHP 8.4+
- `gosuperscript/axiom` ^0.6
- `gosuperscript/monads` ^1.0
- `league/csv` ^9.27
- `league/flysystem` ^3.0

## License

See the package metadata for the current license declaration.
