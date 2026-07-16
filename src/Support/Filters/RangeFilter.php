<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Filters;

use Superscript\Axiom\Source;

/**
 * Matches a value against a `[min, max)` band described by two columns.
 *
 * The lower `>=` and upper `<` operations are bound from Axiom's composed
 * dialect against the declared types of the value, minimum, and maximum.
 */
final readonly class RangeFilter implements Filter
{
    public function __construct(
        public string|int $minColumn,
        public string|int $maxColumn,
        public Source $value,
    ) {}
}
