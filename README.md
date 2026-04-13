# Lookup Resolver

A high-performance PHP library for querying CSV/TSV files with streaming, dynamic filtering, aggregate functions, and range-based banding.

## Features

- **Memory-Efficient Streaming**: O(1) memory complexity - processes records one-at-a-time
- **Seven Aggregate Functions**: `first`, `last`, `min`, `max`, `count`, `sum`, `avg`, `all`
- **Explicit Filter API**: `ValueFilter` and `RangeFilter` for clear, self-documenting code
- **Range-Based Banding**: Support for scenarios like tax brackets, premium tiers, shipping rates
- **Dynamic Filter Resolution**: Use nested lookups and symbols as filter values
- **Strongly-Typed Value Objects**: Enhanced type safety with immutable aggregates
- **Early Exit Optimization**: `first` aggregate stops reading after first match (465x faster)
- **Flexible Storage**: Support for local files, S3, and other storage backends via Flysystem
- **PHP 8.4 Compatible**: Full compatibility with latest PHP features

## Installation

```bash
composer require gosuperscript/axiom-lookup
```

## Quick Start

```php
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Lookup\{LookupResolver, LookupSource};
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Resolvers\DelegatingResolver;
use Superscript\Axiom\Sources\StaticSource;

// Create a filesystem instance (local filesystem example)
$adapter = new LocalFilesystemAdapter('/path/to/data');
$filesystem = new Filesystem($adapter);

// Set up the resolver with the filesystem
$resolver = new DelegatingResolver([
    LookupSource::class => LookupResolver::class,
]);
$resolver->instance(\League\Flysystem\FilesystemOperator::class, $filesystem);

// Define a lookup source
$lookup = new LookupSource(
    path: 'products.csv',
    filters: [new ValueFilter('category', new StaticSource('Electronics'))],
    columns: 'price'
);

// Wrap the source in an Expression and invoke it like a function
$query = new Expression($lookup, $resolver);
$result = $query(); // Result<Option<mixed>>
```

## Using Different Storage Backends

The library uses [Flysystem](https://flysystem.thephpleague.com/) for filesystem abstraction, enabling you to read CSV files from various storage backends. The filesystem adapter is configured on the `LookupResolver`, allowing you to set the right filesystem adapter at runtime.

### Local Filesystem

```php
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Lookup\{LookupResolver, LookupSource};
use Superscript\Axiom\Resolvers\DelegatingResolver;

$adapter = new LocalFilesystemAdapter('/path/to/data');
$filesystem = new Filesystem($adapter);

// Configure resolver with filesystem
$resolver = new DelegatingResolver([
    LookupSource::class => LookupResolver::class,
]);
$resolver->instance(\League\Flysystem\FilesystemOperator::class, $filesystem);

$lookup = new LookupSource(
    path: 'users.csv',
    filters: [new ValueFilter('status', new StaticSource('active'))],
    columns: ['name', 'email']
);

$result = (new Expression($lookup, $resolver))();
```

### Amazon S3

```php
use League\Flysystem\Filesystem;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Aws\S3\S3Client;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Lookup\{LookupResolver, LookupSource};
use Superscript\Axiom\Resolvers\DelegatingResolver;

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

// Configure resolver with S3 filesystem
$resolver = new DelegatingResolver([
    LookupSource::class => LookupResolver::class,
]);
$resolver->instance(\League\Flysystem\FilesystemOperator::class, $filesystem);

$lookup = new LookupSource(
    path: 'data/products.csv',
    filters: [new ValueFilter('category', new StaticSource('Books'))],
    columns: 'price'
);

$result = (new Expression($lookup, $resolver))();
```

### Reusing an Expression with Different Inputs

`Expression` wraps a source together with the resolver and any named
[`Definitions`](https://github.com/gosuperscript/axiom), and lets you invoke
it like a function with per-call `bindings`. This is handy when the same
lookup shape is reused with different symbol values:

```php
use Superscript\Axiom\Expression;
use Superscript\Axiom\Resolvers\SymbolResolver;
use Superscript\Axiom\Sources\SymbolSource;

// Register SymbolResolver so `SymbolSource` placeholders can be resolved
$resolver = new DelegatingResolver([
    LookupSource::class => LookupResolver::class,
    SymbolSource::class => SymbolResolver::class,
]);
$resolver->instance(\League\Flysystem\FilesystemOperator::class, $filesystem);

// A lookup parameterised by a `category` symbol supplied at call time
$lookup = new LookupSource(
    path: 'products.csv',
    filters: [new ValueFilter('category', new SymbolSource('category'))],
    columns: 'price'
);

$query = new Expression($lookup, $resolver);

// Inspect required parameters derived from the source AST
$query->parameters(); // ['category']

// Invoke with bindings — equivalent forms
$electronics = $query(['category' => 'Electronics']);
$books       = $query->call(['category' => 'Books']);
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
- league/csv ^9.27.0
- league/flysystem ^3.0
- gosuperscript/monads

## Documentation

For detailed documentation, examples, and API reference, see the main README.md file.

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

- **Memory**: ~6.86MB constant usage regardless of file size
- **Early Exit**: First aggregate is 465x faster than full scan
- **Scalability**: Linear time scaling with row count
- **Validated**: Comprehensive benchmarks with files up to 100k rows

## License

Proprietary

## Credits

Developed by GoSuperscript
# Rebased on latest main