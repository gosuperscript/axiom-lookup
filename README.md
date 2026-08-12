# Axiom Lookup

A high-performance PHP library for querying CSV/TSV files with streaming, dynamic filtering, aggregate functions, and range-based banding — packaged as a first-class Axiom source.

## Features

- **Memory-Efficient Streaming**: O(1) memory complexity with the default reader - processes records one-at-a-time
- **Pluggable Reads**: a host may inject a `LookupSourceReader` that answers indexed equality lookups from its own store, see [Indexed reads](#indexed-reads-the-reader-seam)
- **Eight Aggregate Functions**: `first`, `last`, `min`, `max`, `count`, `sum`, `avg`, `all` — enumerable at runtime, see [Aggregates](#aggregates)
- **Explicit Filter API**: `ValueFilter` and `RangeFilter` for clear, self-documenting code
- **Range-Based Banding**: Support for scenarios like tax brackets, premium tiers, shipping rates
- **Dynamic Filter Resolution**: Use nested lookups and symbols as filter values
- **Dialect-Native Comparisons**: Filter operators are compiled from the same composed Axiom dialect as ordinary infix expressions
- **Typed CSV Boundaries**: Declare column types when filters need coercion or non-string operations; undeclared columns remain raw strings
- **Serialisable descriptions**: a `LookupSource` is pure data — the filesystem lives on the `LookupExtension`, so a lookup tree can be persisted and loaded later
- **Honest Types**: numeric aggregates declare `Option<Number>`, `all` declares `List<Unknown>`, and aggregates returning one raw row/cell declare `Option<Unknown>`
- **Early Exit Optimization**: `first` aggregate stops reading after first match
- **Flexible Storage**: Support for local files, S3, and other storage backends via Flysystem
- **PHP 8.4 Compatible**: Full compatibility with latest PHP features

## Installation

```bash
composer require gosuperscript/axiom-lookup
```

## Quick Start

A `LookupSource` is pure, serialisable data — the file path, the filters, the columns, the aggregate. The filesystem the read needs is injected into a `LookupExtension`, which you compose onto the dialect; the source itself carries no live collaborator. Compile the source into a `Program`, then invoke it.

```php
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Lookup\LookupExtension;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Sources\StaticSource;

// Create a filesystem instance (local filesystem example)
$adapter = new LocalFilesystemAdapter('/path/to/data');
$filesystem = new Filesystem($adapter);

// Compose the lookup extension onto the dialect — this is where the filesystem lives
$dialect = Dialect::core()->with(new LookupExtension($filesystem));

// Define a lookup source — pure data, no filesystem
$lookup = new LookupSource(
    path: 'products.csv',
    filters: [new ValueFilter('category', new StaticSource('Electronics'))],
    columns: ['price'],
);

// Compile once, then invoke the program like a function
$program = (new Expression($lookup, dialect: $dialect))->compile()->unwrap();
$result = $program(); // Result<Option<mixed>, Throwable>
```

## Aggregates

`LookupSource::$aggregate` is one of the names `AggregateKind` defines, and that enum is the only place the list lives. Ask it rather than restating the list:

```php
use Superscript\Axiom\Lookup\Support\Aggregates\AggregateKind;

AggregateKind::names();
// ['first', 'last', 'count', 'sum', 'avg', 'min', 'max', 'all']

AggregateKind::Sum->requiresColumn();    // true
AggregateKind::Count->requiresColumn();  // false
```

`requiresColumn()` is the difference between the aggregates that read whole records and those that read one column's values. `first`, `last`, `count` and `all` count matching records or extract the requested columns from them, so they need no `aggregateColumn`. `sum`, `avg`, `min` and `max` need one — there is no sum of a whole record — and refuse without it:

```php
$lookup = new LookupSource(path: 'products.csv', aggregate: 'sum');
$program = (new Expression($lookup, dialect: $dialect))->compile()->unwrap();
$program();
// Err(RuntimeException: aggregateColumn is required when using 'sum' aggregate)
// — raised by the first matching record, so a lookup that matches nothing
//   still returns None. Check the kind up front to catch it either way.

new LookupSource(path: 'products.csv', aggregate: 'sum', aggregateColumn: 'price');
// ✓
```

So a caller validating a lookup before running it, or offering a column picker only where a column means something, reads both facts from the kind instead of keeping its own copy in step with this package. Given an aggregate state, `$aggregate->kind()` gets back to the same answers.

## Using Different Storage Backends

The library uses [Flysystem](https://flysystem.thephpleague.com/) for filesystem abstraction, enabling you to read CSV files from various storage backends. The filesystem operator is passed to the `LookupExtension`, so you choose the right adapter once when you compose the dialect — every `LookupSource` compiled with it reads through that filesystem.

### Local Filesystem

```php
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Lookup\LookupExtension;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Sources\StaticSource;

$adapter = new LocalFilesystemAdapter('/path/to/data');
$filesystem = new Filesystem($adapter);
$dialect = Dialect::core()->with(new LookupExtension($filesystem));

$lookup = new LookupSource(
    path: 'users.csv',
    filters: [new ValueFilter('status', new StaticSource('active'))],
    columns: ['name', 'email'],
);

$result = (new Expression($lookup, dialect: $dialect))->compile()->unwrap()();
```

### Amazon S3

```php
use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Lookup\LookupExtension;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Sources\StaticSource;

$client = new S3Client([
    'credentials' => ['key' => 'your-key', 'secret' => 'your-secret'],
    'region' => 'us-east-1',
    'version' => 'latest',
]);

$adapter = new AwsS3V3Adapter($client, 'your-bucket-name');
$filesystem = new Filesystem($adapter);
$dialect = Dialect::core()->with(new LookupExtension($filesystem));

$lookup = new LookupSource(
    path: 'data/products.csv',
    filters: [new ValueFilter('category', new StaticSource('Books'))],
    columns: ['price'],
);

$result = (new Expression($lookup, dialect: $dialect))->compile()->unwrap()();
```

### Reusing a Program with Different Inputs

A filter value is a `Source`, so it can be a `SymbolSource` supplied at call time. Declare the symbol's type on the `Expression`; the compiled `Program` then admits it at the boundary and you invoke it with per-call `bindings`:

```php
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Lookup\LookupExtension;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Types\StringType;

$dialect = Dialect::core()->with(new LookupExtension($filesystem));

// A lookup parameterised by a `category` symbol supplied at call time
$lookup = new LookupSource(
    path: 'products.csv',
    filters: [new ValueFilter('category', new SymbolSource('category'))],
    columns: ['price'],
);

$program = (new Expression($lookup, dialect: $dialect, declarations: ['category' => new StringType()]))
    ->compile()
    ->unwrap();

// Invoke with bindings — equivalent forms
$electronics = $program(['category' => 'Electronics']);
$books       = $program->call(['category' => 'Books']);
```

## Indexed reads (the reader seam)

By default every lookup streams its whole file. A host that keeps an indexed copy of a lookup file — a database artifact, for instance — can answer equality lookups from it instead by injecting a `LookupSourceReader`:

```php
new LookupExtension($filesystem);           // default: full CSV stream
new LookupExtension($filesystem, $reader);  // host-provided indexed reader
```

The reader's contract: yield a **superset** of the records the filter pipeline would have matched in a full scan, in the file's **original row order**. Every yielded record still passes the full filter pipeline, so a reader may only change where the file is read — never a lookup's answer. A reader may fail eagerly or lazily (mid-iteration); both surface as the lookup's failure `Result`.

`LookupSource` accepts an optional `index` naming the column an indexed reader may seek on — purely an access-path hint, persisted with the source; declaring it never changes results. Each invocation hands the reader its **probes**: the resolved value of every `==` filter on a `String`-typed column, keyed by column. Skipping a probe is always safe; the filter pipeline still applies every filter.

Probes are byte-equality seeks. They are only sound where the dialect's `String == String` is raw byte equality (the core rule); a host whose dialect overloads that comparison must not serve probes from a byte-built index.

For observability the evaluation annotates `scan` with the strategy that answered (`full-stream`, or the indexed reader's own label), at most once, in a stable position before `label`.

## Typed filters and operators

Filters are serialisable descriptions. During compilation, `LookupExtension` compiles each filter value and binds its operator from the expression's composed dialect. The resulting operation is reused for every row; filters do not contain a resolver and do not reimplement comparisons at runtime.

CSV cells are strings by default. Add a `schema` entry when a filter should read a cell as another Axiom type. For example, numeric ordering needs a numeric column declaration:

```php
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Types\NumberType;

$lookup = new LookupSource(
    path: 'users.csv',
    filters: [new ValueFilter('age', new StaticSource(30), '>=')],
    columns: ['name'],
    schema: ['age' => new NumberType()],
);
```

`RangeFilter` uses the same mechanism for its `[minimum, maximum)` test, so both bound columns should declare an orderable type:

```php
use Superscript\Axiom\Lookup\Support\Filters\RangeFilter;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Types\NumberType;

$lookup = new LookupSource(
    path: 'premium_bands.csv',
    filters: [new RangeFilter('minimum', 'maximum', new SymbolSource('turnover'))],
    columns: ['premium'],
    schema: [
        'minimum' => new NumberType(),
        'maximum' => new NumberType(),
    ],
);
```

Extension-owned operators work without lookup-specific integration. If an extension in the dialect owns `equals-ignore-case` for `String × String → Boolean`, a `ValueFilter(..., 'equals-ignore-case')` binds that exact rule. Unknown operators, incompatible operands, and operators that do not return `Boolean` are compile errors. A cell that cannot be coerced to its declared type is a runtime boundary error rather than a silent string comparison.

An `all` lookup is a total collection: no matching rows produce `[]`, not absence. This makes a nested collection lookup usable as the right side of `in` after one explicit element-type bridge:

```php
use Superscript\Axiom\Sources\Coerce;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\StringType;

$cities = new LookupSource(
    path: 'allowed-cities.csv',
    columns: ['city'],
    aggregate: 'all',
);

$users = new LookupSource(
    path: 'users.csv',
    filters: [new ValueFilter(
        'city',
        new Coerce(new ListType(new StringType()), $cities),
        'in',
    )],
    columns: ['name'],
    aggregate: 'all',
);
```

### Other Storage Options

Flysystem supports many adapters including:
- FTP/SFTP
- Azure Blob Storage
- Google Cloud Storage
- In-memory filesystem
- And many more...

See the [Flysystem documentation](https://flysystem.thephpleague.com/docs/) for more options.

## Requirements

- PHP 8.4+
- gosuperscript/axiom (the typesafe compile/Program line)
- league/csv ^9.27.0
- league/flysystem ^3.0
- gosuperscript/monads

## Testing

```bash
composer test          # Run all tests
composer test:unit     # Run unit tests
composer test:types    # Run static analysis
composer test:infection # Run mutation tests
```

## Benchmarking

```bash
composer bench              # Run all benchmarks
composer bench:aggregate    # Test aggregate functions
composer bench:memory       # Test memory efficiency
```

## Performance Characteristics

- **Memory**: with the default full-scan reader, constant usage regardless of file size (single-pass streaming); an injected indexed reader owns its own profile
- **Early Exit**: `first` aggregate stops after the first match
- **Scalability**: Linear time scaling with row count
- **Validated**: Comprehensive benchmarks with files up to 100k rows

## License

Proprietary

## Credits

Developed by GoSuperscript
