<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Filters;

use RuntimeException;
use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Axiom\Operators\ValueEquality;
use Superscript\Axiom\Source;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * Matches a single column against a comparison value.
 *
 * Whether this runs as a first-class Axiom expression is settled by the
 * cell's type: a raw CSV cell is `Unknown`, so the match cannot resolve
 * through the compile-time operator system (see {@see Filter}). But the
 * *comparison itself* is still the engine's, not PHP's — it goes through
 * {@see ValueEquality}, the one authority every other equality site in
 * Axiom uses, so a lookup never disagrees with the language about what
 * "equal" means (no `'1e2' == '100'` juggling). Two operators are
 * supported — `==` (value equality against the cell) and `in` (membership
 * in a list value) — and any other operator is an honest error rather than
 * a silent no-match.
 */
final readonly class ValueFilter implements Filter
{
    public function __construct(
        public string|int $column,
        public Source $value,
        public string $operator = '==',
    ) {}

    public function matches(CsvRecord $record, mixed $value): Result
    {
        $cell = $record->get($this->column);

        return match ($this->operator) {
            '==' => Ok(ValueEquality::equals($cell, $value)),
            'in' => Ok(is_array($value) && array_any($value, fn(mixed $item) => ValueEquality::equals($item, $cell))),
            default => Err(new RuntimeException("Unsupported filter operator: {$this->operator}")),
        };
    }
}
