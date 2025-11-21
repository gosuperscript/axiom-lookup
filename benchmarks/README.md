# Benchmarks

This directory contains PHPBench benchmarks for the lookup resolver functionality.

## Running Benchmarks

### Run all benchmarks
```bash
composer bench
```

### Run specific groups
```bash
# Test all aggregate functions
composer bench:aggregate

# Test memory efficiency
composer bench:memory

# Test exact filters only
vendor/bin/phpbench run --group=exact --report=default

# Test with different file sizes
vendor/bin/phpbench run --group=small --report=default
vendor/bin/phpbench run --group=medium --report=default
vendor/bin/phpbench run --group=large --report=default
vendor/bin/phpbench run --group=huge --report=default
```

### Advanced options
```bash
# Generate detailed report with memory metrics
vendor/bin/phpbench run --report=aggregate --report=env

# Compare with baseline
vendor/bin/phpbench run --report=default --ref=baseline

# Store results as baseline
vendor/bin/phpbench run --tag=baseline

# Profile with xdebug
vendor/bin/phpbench run --profile
```

## Benchmark Categories

### File Sizes
- **small**: 100 rows
- **medium**: 1,000 rows
- **large**: 10,000 rows
- **huge**: 100,000 rows

### Groups
- **exact**: Exact column matching filters
- **range**: Range-based banding filters
- **aggregate**: All aggregate functions (first, last, min, max, count, sum, avg)
- **complex**: Multiple filters combined
- **memory**: Memory efficiency tests

## What's Being Measured

The benchmarks measure:
- **Execution time**: How fast operations complete
- **Memory usage**: Memory consumption during operations
- **Scalability**: Performance with different file sizes
- **Early exit optimization**: Verification that `first` aggregate stops early
- **Streaming efficiency**: O(1) memory complexity validation

## Expected Results

- **First aggregate**: Should be significantly faster than other aggregates (early exit)
- **Count aggregate**: Should have constant memory usage regardless of file size
- **Memory usage**: Should remain stable (~5-10MB) even with huge files
- **Time complexity**: Should scale linearly with file size (O(n))

## Example Output

```
PHPBench (1.4.3) running benchmarks...

benchExactFilterSmallFile      I4 P0   ✔ 10 r     0.234ms (±2.34%)
benchExactFilterMediumFile     I4 P0   ✔ 10 r     2.123ms (±1.87%)
benchExactFilterLargeFile      I4 P0   ✔ 5 r     21.456ms (±3.12%)
benchFirstAggregate            I4 P0   ✔ 10 r     5.234ms (±1.45%)
benchCountAggregate            I4 P0   ✔ 10 r    20.123ms (±2.34%)

Subjects: 12, Assertions: 0, Failures: 0, Errors: 0
```
