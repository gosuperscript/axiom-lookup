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
 * Filter matching is host-owned domain logic, deliberately kept out of
 * Axiom's typed operator system: a raw CSV cell has type `Unknown`, and
 * `Unknown` refuses every operator by design, so a lookup's `city == 'NYC'`
 * is not — and cannot be — a first-class Axiom expression. These filters
 * compare values in plain PHP.
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
