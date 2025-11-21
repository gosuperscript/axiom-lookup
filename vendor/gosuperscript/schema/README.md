# Schema Library

A powerful PHP library for data transformation, type validation, and expression evaluation. This library provides a flexible framework for defining data schemas, transforming values, and evaluating complex expressions with type safety.

## Features

- **Type System**: Robust type validation and transformation for numbers, strings, booleans, lists, and dictionaries
- **Expression Evaluation**: Support for infix expressions with custom operators
- **Resolver Pattern**: Pluggable resolver system for different data sources
- **Symbol Registry**: Named value resolution and reuse
- **Operator Overloading**: Extensible operator system for custom evaluation logic
- **Monadic Error Handling**: Built on functional programming principles using Result and Option types

## Requirements

- PHP 8.4 or higher
- ext-intl extension

## Installation

```bash
composer require gosuperscript/schema
```

## Quick Start

### Basic Type Transformation

```php
<?php

use Superscript\Schema\Types\NumberType;
use Superscript\Schema\Sources\StaticSource;
use Superscript\Schema\Sources\ValueDefinition;
use Superscript\Schema\Resolvers\DelegatingResolver;
use Superscript\Schema\Resolvers\StaticResolver;
use Superscript\Schema\Resolvers\ValueResolver;

// Create a resolver with basic capabilities
$resolver = new DelegatingResolver([
    StaticResolver::class,
    ValueResolver::class,
]);

// Transform a string to a number
$source = new ValueDefinition(
    type: new NumberType(),
    source: new StaticSource('42')
);

$result = $resolver->resolve($source);
$value = $result->unwrap()->unwrap(); // 42 (as integer)
```

### Expression Evaluation

```php
<?php

use Superscript\Schema\Sources\InfixExpression;
use Superscript\Schema\Sources\StaticSource;
use Superscript\Schema\Sources\SymbolSource;
use Superscript\Schema\SymbolRegistry;
use Superscript\Schema\Resolvers\DelegatingResolver;
use Superscript\Schema\Resolvers\InfixResolver;
use Superscript\Schema\Resolvers\SymbolResolver;

// Set up resolver with symbol support
$resolver = new DelegatingResolver([
    StaticResolver::class,
    InfixResolver::class,
    SymbolResolver::class,
]);

// Register symbols
$resolver->instance(SymbolRegistry::class, new SymbolRegistry([
    'PI' => new StaticSource(3.14159),
    'radius' => new StaticSource(5),
]));

// Calculate: PI * radius * radius (area of circle)
$expression = new InfixExpression(
    left: new SymbolSource('PI'),
    operator: '*',
    right: new InfixExpression(
        left: new SymbolSource('radius'),
        operator: '*',
        right: new SymbolSource('radius')
    )
);

$result = $resolver->resolve($expression);
$area = $result->unwrap()->unwrap(); // ~78.54
```

### Using Namespaced Symbols

The `SymbolRegistry` supports namespaces to organize related symbols:

```php
<?php

use Superscript\Schema\Sources\StaticSource;
use Superscript\Schema\Sources\SymbolSource;
use Superscript\Schema\SymbolRegistry;

// Create registry with namespaced symbols
$registry = new SymbolRegistry([
    // Global symbols (no namespace)
    'version' => new StaticSource('1.0.0'),
    'debug' => new StaticSource(true),
    
    // Math namespace
    'math' => [
        'pi' => new StaticSource(3.14159),
        'e' => new StaticSource(2.71828),
        'phi' => new StaticSource(1.61803),
    ],
    
    // Constants namespace
    'physics' => [
        'c' => new StaticSource(299792458),      // Speed of light
        'g' => new StaticSource(9.80665),        // Gravitational acceleration
    ],
]);

// Access global symbols
$version = $registry->get('version');           // Some('1.0.0')

// Access namespaced symbols
$pi = $registry->get('pi', 'math');            // Some(3.14159)
$c = $registry->get('c', 'physics');           // Some(299792458)

// Using with SymbolSource
$symbolSource = new SymbolSource('pi', 'math');
$result = $resolver->resolve($symbolSource);   // ~3.14159

// Namespaces provide isolation
$registry->get('pi');                           // None (no global 'pi')
$registry->get('c', 'math');                   // None (no 'c' in math namespace)
```

## Core Concepts

### Types

The library provides several built-in types for data validation and coercion:

#### NumberType
Validates and coerces values to numeric types (int/float):
- Numeric strings: `"42"` → `42`
- Percentage strings: `"50%"` → `0.5`
- Numbers: `42.5` → `42.5`

#### StringType
Validates and coerces values to strings:
- Numbers: `42` → `"42"`
- Stringable objects: converted to string representation
- Special handling for null and empty values

#### BooleanType
Validates and coerces values to boolean:
- Truthy/falsy evaluation
- String representations: `"true"`, `"false"`

#### ListType and DictType
For collections and associative arrays with nested type validation.

### Type API: Assert vs Coerce

The `Type` interface provides two methods for value processing, following the [@azjezz/psl](https://github.com/azjezz/psl) pattern:

- **`assert(T $value): Result<Option<T>>`** - Validates that a value is already of the correct type
- **`coerce(mixed $value): Result<Option<T>>`** - Attempts to convert a value from any type to the target type

**When to use:**
- Use `assert()` when you expect a value to already be the correct type and want strict validation
- Use `coerce()` when you want to transform values from various input types (permissive conversion)

**Example:**
```php
$numberType = new NumberType();

// Assert - only accepts int/float
$result = $numberType->assert(42);      // Ok(Some(42))
$result = $numberType->assert('42');    // Err(TransformValueException)

// Coerce - converts compatible types
$result = $numberType->coerce(42);      // Ok(Some(42))
$result = $numberType->coerce('42');    // Ok(Some(42))
$result = $numberType->coerce('45%');   // Ok(Some(0.45))
```

Both methods return `Result<Option<T>, Throwable>` where:
- `Ok(Some(value))` - successful validation/coercion with a value
- `Ok(None())` - successful but no value (e.g., empty strings)
- `Err(exception)` - failed validation/coercion

**Note:** The `coerce()` method provides the same functionality as the previous `transform()` method.

### Sources

Sources represent different ways to provide data:

- **StaticSource**: Direct values
- **SymbolSource**: Named references to other sources (with optional namespace support)
- **ValueDefinition**: Combines a type with a source for validation and coercion
- **InfixExpression**: Mathematical/logical expressions
- **UnaryExpression**: Single-operand expressions

### Symbol Registry

The `SymbolRegistry` provides a centralized way to manage named values and organize them using namespaces:

**Key Features:**
- **Global Symbols**: Direct symbol registration without namespace isolation
- **Namespaced Symbols**: Group related symbols under namespaces for better organization
- **Isolation**: Symbols in different namespaces are isolated from each other
- **Type Safety**: Only `Source` instances can be registered

**Registration Format:**
```php
new SymbolRegistry([
    'globalSymbol' => new StaticSource(value),    // Global scope
    'namespace' => [                               // Namespaced scope
        'symbol1' => new StaticSource(value1),
        'symbol2' => new StaticSource(value2),
    ],
])
```

**Use Cases:**
- Organize constants by category (math, physics, config)
- Prevent naming conflicts between different domains
- Create clear separation between different symbol contexts
- Improve code maintainability with logical grouping

### Resolvers

Resolvers handle the evaluation of sources:

- **StaticResolver**: Resolves static values
- **ValueResolver**: Applies type coercion using the `coerce()` method
- **InfixResolver**: Evaluates binary expressions
- **SymbolResolver**: Looks up named symbols
- **DelegatingResolver**: Chains multiple resolvers together

### Operators

The library supports various operators through the overloader system:

- **Binary**: `+`, `-`, `*`, `/`, `%`, `**`
- **Comparison**: `==`, `!=`, `<`, `<=`, `>`, `>=`
- **Logical**: `&&`, `||`
- **Special**: `has`, `in`, `intersects`

## Advanced Usage

### Custom Types

Implement the `Type` interface to create custom data validations and coercions:

```php
<?php

use Superscript\Schema\Types\Type;
use Superscript\Monads\Result\Result;
use Superscript\Monads\Result\Err;
use Superscript\Schema\Exceptions\TransformValueException;
use function Superscript\Monads\Result\Ok;
use function Superscript\Monads\Option\Some;

class EmailType implements Type
{
    public function assert(mixed $value): Result
    {
        // Strict validation - only accepts valid email strings
        if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return Ok(Some($value));
        }
        
        return new Err(new TransformValueException(type: 'email', value: $value));
    }
    
    public function coerce(mixed $value): Result
    {
        // Permissive conversion - attempts to convert to email format
        $stringValue = is_string($value) ? $value : strval($value);
        $trimmed = trim($stringValue);
        
        if (filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
            return Ok(Some($trimmed));
        }
        
        return new Err(new TransformValueException(type: 'email', value: $value));
    }
    
    public function compare(mixed $a, mixed $b): bool
    {
        return $a === $b;
    }
    
    public function format(mixed $value): string
    {
        return (string) $value;
    }
}
```

### Custom Resolvers

Create specialized resolvers for specific data sources:

```php
<?php

use Superscript\Schema\Resolvers\Resolver;
use Superscript\Schema\Source;
use Superscript\Monads\Result\Result;

class DatabaseResolver implements Resolver
{
    public function resolve(Source $source): Result
    {
        // Custom resolution logic
        // Connect to database, fetch data, etc.
    }
    
    public static function supports(Source $source): bool
    {
        return $source instanceof DatabaseSource;
    }
}
```

## Development

### Setup

1. Clone the repository
2. Install dependencies: `composer install`
3. Run tests: `composer test`

### Testing

The library uses PHPUnit for testing with 100% code coverage requirements:

```bash
# Run all tests
composer test

# Individual test suites
composer test:unit      # Unit tests
composer test:types     # Static analysis (PHPStan)
composer test:infection # Mutation testing
```

### Code Quality

- **PHPStan**: Level max static analysis
- **Infection**: Mutation testing for test quality
- **Laravel Pint**: Code formatting
- **100% Code Coverage**: Required for all new code

## Architecture

The library follows several design patterns:

- **Strategy Pattern**: Different resolvers for different source types
- **Chain of Responsibility**: DelegatingResolver chains multiple resolvers
- **Factory Pattern**: Type system for creating appropriate transformations
- **Functional Programming**: Extensive use of Result and Option monads

## Error Handling

All type validation and coercion operations return `Result<Option<T>, Throwable>` types:

- `Result::Ok(Some(value))`: Successful validation/coercion with value
- `Result::Ok(None())`: Successful validation/coercion with no value (null/empty)
- `Result::Err(exception)`: Validation/coercion failed with error

This approach ensures:
- No exceptions for normal control flow
- Explicit handling of success/failure cases
- Type-safe null handling

## License

Proprietary - See license terms in your agreement.

## Contributing

This is a private library. Please follow the established patterns and ensure all tests pass before submitting changes.