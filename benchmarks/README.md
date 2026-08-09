# Lookup benchmarks

The benchmark suite exercises streaming lookup behavior across files from 100 to 100,000 rows.

## Run

```bash
composer bench
composer bench:result
composer bench:memory
```

For a specific group or report:

```bash
vendor/bin/phpbench run --group=result --report=default
vendor/bin/phpbench run --report=env
```

## Groups

- `exact`: value-filtered lookups at several file sizes
- `result`: first, last, minimum, maximum, count, sum, and average results
- `range`: range-filtered lookups
- `complex`: multiple filters
- `memory`: large-file streaming behavior

`FirstRow` should stop after its first match. `LastRow`, minimum/maximum selection, and numeric folds scan the input while retaining constant state. `AllRows` is intentionally excluded from constant-memory claims because it materializes every projected row.
