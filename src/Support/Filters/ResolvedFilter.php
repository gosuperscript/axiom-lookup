<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Filters;

use Closure;
use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Monads\Result\Result;
use Throwable;

/**
 * A dialect-bound row predicate paired with the value its Source produced
 * once, before the row loop.
 */
final readonly class ResolvedFilter
{
    public function __construct(
        private mixed $value,
        /** @var Closure(CsvRecord, mixed): Result<bool, Throwable> */
        private Closure $matches,
    ) {}

    /**
     * @return Result<bool, Throwable>
     */
    public function matches(CsvRecord $record): Result
    {
        return ($this->matches)($record, $this->value);
    }
}
