# Copilot Agent Instructions for Schema Library

## Repository Overview

This is a proprietary PHP library for **data transformation, type validation, and expression evaluation**. The library provides a flexible framework for defining data schemas, transforming values, and evaluating complex expressions with type safety using functional programming principles.

**Repository Stats:**
- **Language:** PHP (100%)
- **Size:** ~30 source files, ~20 test files
- **Type:** Library package (`gosuperscript/schema`)
- **Architecture:** Functional programming with monadic error handling

**Key Features:**
- Type system for numbers, strings, booleans, lists, and dictionaries
- Expression evaluation with infix and unary expressions
- Pluggable resolver pattern for different data sources
- Symbol registry for named value resolution
- Operator overloading system
- Built on Result and Option monads for error handling

## Build and Validation Instructions

### Environment Requirements
- **PHP:** 8.4+ (strictly enforced)
- **Extensions:** ext-intl (required)
- **Docker:** Recommended for development (8.4-cli-alpine image)

### Setup Commands
**ALWAYS run these commands in the specified order:**

1. **Install Dependencies:**
   ```bash
   composer install
   ```
   - **Precondition:** PHP 8.4+ must be available
   - **Time:** ~30-60 seconds
   - **Note:** May require GitHub token for private repositories

2. **Docker Setup (if PHP 8.4 unavailable):**
   ```bash
   docker compose build
   docker compose run --rm php composer install
   ```
   - **Time:** 2-5 minutes for initial build
   - **Note:** Network connectivity required for base image

### Testing Commands
**100% code coverage is required for all new code.**

```bash
# Run all tests (recommended)
composer test

# Individual test suites
composer test:unit      # PHPUnit tests (requires 100% coverage)
composer test:types     # PHPStan static analysis (level max)
composer test:infection # Mutation testing (100% MSI required)
```

**Test Execution Times:**
- Unit tests: ~10-30 seconds
- Static analysis: ~5-15 seconds  
- Mutation testing: ~1-3 minutes

### Code Quality Tools

1. **PHPStan (Static Analysis):**
   ```bash
   vendor/bin/phpstan analyse
   ```
   - Level: max (strictest)
   - **Always pass** before submitting changes

2. **Laravel Pint (Code Formatting):**
   ```bash
   vendor/bin/pint
   ```
   - Preset: PER (PHP Evolving Recommendations)
   - Auto-fixes code style issues

3. **Infection (Mutation Testing):**
   ```bash
   vendor/bin/infection --threads=max --show-mutations
   ```
   - Minimum MSI: 100% (all mutants must be killed)
   - **Critical:** Tests quality validation

### Docker Environment
```bash
# Build environment
docker compose build

# Run commands in container
docker compose run --rm php composer install
docker compose run --rm php composer test
docker compose run --rm php vendor/bin/phpstan analyse
```

## Project Layout and Architecture

### Core Architecture Patterns
- **Strategy Pattern:** Different resolvers for different source types
- **Chain of Responsibility:** DelegatingResolver chains multiple resolvers  
- **Factory Pattern:** Type system creates appropriate transformations
- **Functional Programming:** Result and Option monads throughout

### Directory Structure
```
src/
├── Exceptions/          # Custom exception classes
├── Operators/           # Operator overloading system
├── Resolvers/           # Source resolution strategies
├── Sources/             # Data source definitions
├── Types/               # Type validation and transformation
├── Source.php           # Base source interface
└── SymbolRegistry.php   # Named value registry

tests/
├── KitchenSink/         # Integration tests
├── Operators/           # Operator tests
├── Resolvers/           # Resolver tests
├── Types/               # Type system tests
└── *Test.php            # Unit tests
```

### Key Source Files

**Core Interfaces:**
- `src/Source.php` - Base interface for all data sources
- `src/Resolvers/Resolver.php` - Resolver interface template
- `src/Types/Type.php` - Type transformation interface

**Main Implementation:**
- `src/Resolvers/DelegatingResolver.php` - Main resolver chain
- `src/SymbolRegistry.php` - Symbol management
- `src/Types/NumberType.php` - Example type implementation

### Configuration Files

**Build Configuration:**
- `composer.json` - Dependencies and scripts
- `phpunit.xml.dist` - Test configuration
- `phpstan.neon.dist` - Static analysis rules
- `infection.json5` - Mutation testing config
- `pint.json` - Code style rules

**Docker Configuration:**
- `Dockerfile` - PHP 8.4 Alpine development environment
- `docker-compose.yaml` - Development services

### GitHub Workflows
Located in `.github/workflows/tests.yaml`:
- **Test Job:** Runs on PHP 8.4 with matrix for prefer-lowest/prefer-stable
- **Types Job:** Static analysis validation
- **Timeout:** 5 minutes per job
- **Extensions:** Includes intl, bcmath, and testing extensions

### Dependencies

**Production:**
- `azjezz/psl` - PHP Standard Library utilities
- `brick/math` - Arbitrary precision mathematics
- `gosuperscript/monads` - Result/Option monad implementation
- `illuminate/container` - Dependency injection container
- `sebastian/exporter` - Value exporting utilities

**Development:**
- `phpunit/phpunit` (v12.0+) - Testing framework
- `phpstan/phpstan` (v2.1+) - Static analysis
- `infection/infection` - Mutation testing
- `laravel/pint` - Code formatting

### Validation Pipeline

**Local Development Checklist:**
1. Run `composer test:types` (must pass)
2. Run `composer test:unit` (100% coverage required)  
3. Run `composer test:infection` (100% MSI required)
4. Optionally run `vendor/bin/pint` for formatting

**CI/CD Validation:**
- All tests run on PHP 8.4 only
- Matrix testing with prefer-lowest and prefer-stable
- Parallel execution of unit tests and static analysis
- **No deployment** - library package only

### Common Patterns and Usage

**Creating New Types:**
- Implement `Type` interface with transform(), compare(), format()
- Return `Result<Option<T>, Throwable>` from transform()
- Use functional approach with Result monads

**Creating New Resolvers:**
- Implement `Resolver` interface 
- Add static supports() method for source type checking
- Register in DelegatingResolver constructor array

**Error Handling:**
- All operations return Result types (Ok/Err)
- No exceptions for normal control flow
- Option types handle null/empty values safely

### Testing Requirements

**Code Coverage:** 100% line coverage mandatory
**Mutation Testing:** 100% Mutation Score Indicator required
**Static Analysis:** PHPStan level max with no errors

**Test Structure:**
- Use PHPUnit 12+ attributes (`#[Test]`, `#[CoversClass]`)
- Integration tests in `tests/KitchenSink/`
- Unit tests mirror source structure
- Use `#[CoversNothing]` for integration tests

---

## Agent Instructions

**Trust these instructions** and only search/explore when information is incomplete or incorrect. This repository requires PHP 8.4 and has strict quality requirements - always validate changes with the full test suite before submitting.

**For type system changes:** Focus on functional programming patterns and monadic error handling.
**For resolver changes:** Follow the chain of responsibility pattern and ensure proper source type checking.
**For new features:** Maintain 100% test coverage and mutation score requirements.