# Lookup Resolver

A high-performance PHP library for querying CSV/TSV files with streaming, dynamic filtering, aggregate functions, and range-based banding.

## Features

- **Memory-Efficient Streaming**: O(1) memory complexity - processes records one-at-a-time
- **Seven Aggregate Functions**: `first`, `last`, `min`, `max`, `count`, `sum`, `avg`
- **Explicit Filter API**: `ExactFilter` and `RangeFilter` for clear, self-documenting code
- **Range-Based Banding**: Support for scenarios like tax brackets, premium tiers, shipping rates
- **Dynamic Filter Resolution**: Use nested lookups and symbols as filter values
- **Strongly-Typed Value Objects**: Enhanced type safety with immutable aggregates
- **Early Exit Optimization**: `first` aggregate stops reading after first match (465x faster)
- **PHP 8.4 Compatible**: Full compatibility with latest PHP features

## Installation

```bash
composer require gosuperscript/lookup-resolver
```

## Quick Start

```php
use Superscript\LookupResolver\{LookupSource, ExactFilter, StaticSource};

// Simple lookup
$lookup = new LookupSource(
    filePath: '/data/products.csv',
    filters: [new ExactFilter('category', new StaticSource('Electronics'))],
    columns: 'price'
);
```

## Requirements

- PHP 8.4+
- league/csv ^9.27.0
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
