# Contributing to Axiom Lookup

Thank you for your interest in contributing to Axiom Lookup! We welcome contributions from the community.

## How to Contribute

### Reporting Bugs

If you find a bug, please open an issue on GitHub with:
- A clear, descriptive title
- Steps to reproduce the issue
- Expected behavior
- Actual behavior
- Your PHP version and environment details

### Suggesting Features

We welcome feature suggestions! Please open an issue with:
- A clear description of the feature
- The use case and benefits
- Any implementation ideas you may have

### Submitting Pull Requests

1. **Fork the repository** and create your branch from `main`
2. **Make your changes** following our coding standards
3. **Add tests** for any new functionality
4. **Ensure all tests pass** by running `composer test`
5. **Run code quality tools**:
   - `composer test:types` - Run PHPStan static analysis
   - `composer test:unit` - Run unit tests with 100% coverage requirement
   - `composer test:infection` - Run mutation tests
6. **Format your code** using Laravel Pint (follows PSR-12)
7. **Commit your changes** with clear, descriptive commit messages
8. **Submit a pull request** with a description of your changes

## Development Setup

1. Clone the repository:
   ```bash
   git clone https://github.com/gosuperscript/axiom-lookup.git
   cd axiom-lookup
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Run the test suite:
   ```bash
   composer test
   ```

## Coding Standards

This project follows:
- **PSR-12** coding style (enforced by Laravel Pint)
- **Strict types** declaration in all PHP files
- **100% code coverage** requirement
- **PHPStan** at maximum level for static analysis
- **Immutable value objects** where applicable

### Running Code Quality Tools

```bash
# Run all tests (types, unit, mutation)
composer test

# Run individual test suites
composer test:types      # PHPStan static analysis
composer test:unit       # PHPUnit with coverage
composer test:infection  # Mutation testing

# Format code
vendor/bin/pint

# Run benchmarks
composer bench
composer bench:aggregate
composer bench:memory
```

## Code Review Process

All submissions require review. We use GitHub pull requests for this purpose.

- Maintainers will review your PR as soon as possible
- Address any feedback or requested changes
- Once approved, a maintainer will merge your PR

## Testing Requirements

- All new features must include tests
- Maintain 100% code coverage
- Tests must pass mutation testing with Infection
- Static analysis must pass without errors

## Documentation

- Update relevant documentation for any changes
- Add examples for new features
- Keep the README.md up to date

## Questions?

Feel free to open an issue for any questions about contributing!

## License

By contributing to Axiom Lookup, you agree that your contributions will be licensed under the MIT License.
