<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Filters;

use Closure;
use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Monads\Result\Result;
use Throwable;

/**
 * A dialect-bound row predicate paired with the value its Source produced
 * once, before the row loop. The value is readable so the extension can steer
 * I/O by it, and it carries the column that value may be sought on — the two
 * halves of a probe travel together, so no caller has to re-pair them.
 */
final readonly class ResolvedFilter
{
    public function __construct(
        public mixed $value,
        /** @var Closure(CsvRecord, mixed): Result<bool, Throwable> */
        private Closure $matches,
        /** The column an indexed reader may seek this value on; null when the filter is not probe-eligible. */
        public string|int|null $probeColumn = null,
    ) {}

    /**
     * @return Result<bool, Throwable>
     */
    public function matches(CsvRecord $record): Result
    {
        return ($this->matches)($record, $this->value);
    }
}
