<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Filters;

use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Axiom\Source;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Ok;

/**
 * Matches a value against a `[min, max)` band described by two columns.
 *
 * Like {@see ValueFilter}, the comparison is plain PHP domain logic, not an
 * Axiom operator (see {@see Filter}).
 */
final readonly class RangeFilter implements Filter
{
    public function __construct(
        public string|int $minColumn,
        public string|int $maxColumn,
        public Source $value,
    ) {}

    public function matches(CsvRecord $record, mixed $value): Result
    {
        if (! $record->has($this->minColumn) || ! $record->has($this->maxColumn)) {
            return Ok(false);
        }

        $minValue = $record->get($this->minColumn);
        $maxValue = $record->get($this->maxColumn);

        // [min, max) range
        if (is_numeric($value) && is_numeric($minValue) && is_numeric($maxValue)) {
            return Ok($value >= $minValue && $value < $maxValue);
        }

        return Ok($value >= $minValue && $value < $maxValue);
    }
}
