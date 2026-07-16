<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Filters;

use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Axiom\Source;
use Superscript\Monads\Result\Result;
use Throwable;

/**
 * A row predicate for a lookup.
 *
 * Filter matching runs at evaluation time, not through Axiom's compile-time
 * operator system: a raw CSV cell has type `Unknown`, and `Unknown` refuses
 * every operator by design, so a lookup's `city == 'NYC'` is not — and cannot
 * be — a first-class, compile-checked Axiom expression. The comparison is
 * still the engine's, though: equality-based filters compare through
 * {@see \Superscript\Axiom\Operators\ValueEquality}, the same authority the
 * language uses everywhere else, so lookups never disagree about what "equal"
 * means (no PHP type-juggling).
 */
interface Filter
{
    public Source $value {
        get;
    }

    /**
     * @return Result<bool, Throwable>
     */
    public function matches(CsvRecord $record, mixed $value): Result;
}
