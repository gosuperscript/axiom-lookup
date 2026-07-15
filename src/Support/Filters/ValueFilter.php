<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Filters;

use RuntimeException;
use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Axiom\Source;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * Matches a single column against a comparison value.
 *
 * The comparison is plain PHP, not an Axiom operator: the cell's type is
 * `Unknown`, so this is internal domain logic (see {@see Filter}). Two
 * operators are supported — `==` (loose equality against the cell) and
 * `in` (membership in a list value) — and any other operator is an honest
 * error rather than a silent no-match.
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
            '==' => Ok($cell == $value),
            'in' => Ok(is_array($value) && in_array($cell, $value)),
            default => Err(new RuntimeException("Unsupported filter operator: {$this->operator}")),
        };
    }
}
