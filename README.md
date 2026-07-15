# Axiom Lookup

A high-performance PHP library for querying CSV/TSV files with streaming, dynamic filtering, aggregate functions, and range-based banding — packaged as a first-class Axiom source.

## Features

- **Memory-Efficient Streaming**: O(1) memory complexity - processes records one-at-a-time
- **Eight Aggregate Functions**: `first`, `last`, `min`, `max`, `count`, `sum`, `avg`, `all`
- **Explicit Filter API**: `ValueFilter` and `RangeFilter` for clear, self-documenting code
- **Range-Based Banding**: Support for scenarios like tax brackets, premium tiers, shipping rates
- **Dynamic Filter Resolution**: Use nested lookups and symbols as filter values
- **Honest Types**: numeric aggregates declare `Option<Number>`; every other aggregate declares `Option<Unknown>` (a raw CSV cell), bridged downstream with a `Coerce`/`Ascription`
- **Early Exit Optimization**: `first` aggregate stops reading after first match
- **Flexible Storage**: Support for local files, S3, and other storage backends via Flysystem
- **PHP 8.4 Compatible**: Full compatibility with latest PHP features

## Installation

```bash
composer require gosuperscript/axiom-lookup
```

## Quick Start

`LookupSource` is a `TypedSource`: it compiles itself, carrying both the type
the lookup produces and the streaming evaluation that produces it. There is no
resolver to register — the Flysystem operator is a constructor dependency, and
you compile the source into a `Program` and invoke it.

```php
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Sources\StaticSource;

// Create a filesystem instance (local filesystem example)
$adapter = new LocalFilesystemAdapter('/path/to/data');
$filesystem = new Filesystem($adapter);

// Define a lookup source; the filesystem is a constructor dependency
$lookup = new LookupSource(
    path: 'products.csv',
    filesystem: $filesystem,
    filters: [new ValueFilter('category', new StaticSource('Electronics'))],
    columns: ['price'],
);

// Compile once, then invoke the program like a function
$program = (new Expression($lookup))->compile()->unwrap();
$result = $program(); // Result<Option<mixed>, Throwable>
```

## Using Different Storage Backends

The library uses [Flysystem](https://flysystem.thephpleague.com/) for filesystem abstraction, enabling you to read CSV files from various storage backends. The filesystem operator is passed to each `LookupSource`, so you choose the right adapter when you build the source.

### Local Filesystem

```php
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Sources\StaticSource;

$adapter = new LocalFilesystemAdapter('/path/to/data');
$filesystem = new Filesystem($adapter);

$lookup = new LookupSource(
    path: 'users.csv',
    filesystem: $filesystem,
    filters: [new ValueFilter('status', new StaticSource('active'))],
    columns: ['name', 'email'],
);

$result = (new Expression($lookup))->compile()->unwrap()();
```

### Amazon S3

```php
use League\Flysystem\Filesystem;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Aws\S3\S3Client;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Sources\StaticSource;

$client = new S3Client([
    'credentials' => [
        'key'    => 'your-key',
        'secret' => 'your-secret',
    ],
    'region' => 'us-east-1',
    'version' => 'latest',
]);

$adapter = new AwsS3V3Adapter($client, 'your-bucket-name');
$filesystem = new Filesystem($adapter);

$lookup = new LookupSource(
    path: 'data/products.csv',
    filesystem: $filesystem,
    filters: [new ValueFilter('category', new StaticSource('Books'))],
    columns: ['price'],
);

$result = (new Expression($lookup))->compile()->unwrap()();
```

### Reusing a Program with Different Inputs

A filter value is a `Source`, so it can be a `SymbolSource` supplied at call
time. Declare the symbol's type on the `Expression`; the compiled `Program`
then admits it at the boundary and you invoke it with per-call `bindings`:

```php
use Superscript\Axiom\Expression;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Types\StringType;

// A lookup parameterised by a `category` symbol supplied at call time
$lookup = new LookupSource(
    path: 'products.csv',
    filesystem: $filesystem,
    filters: [new ValueFilter('category', new SymbolSource('category'))],
    columns: ['price'],
);

$program = (new Expression($lookup, declarations: ['category' => new StringType()]))
    ->compile()
    ->unwrap();

// Invoke with bindings — equivalent forms
$electronics = $program(['category' => 'Electronics']);
$books       = $program->call(['category' => 'Books']);
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
- gosuperscript/axiom ^0.5.0
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

- **Memory**: constant usage regardless of file size (single-pass streaming)
- **Early Exit**: `first` aggregate stops after the first match
- **Scalability**: Linear time scaling with row count
- **Validated**: Comprehensive benchmarks with files up to 100k rows

## License

Proprietary

## Credits

Developed by GoSuperscript
