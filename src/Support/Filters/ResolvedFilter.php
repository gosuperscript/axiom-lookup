<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Filters;

use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Monads\Result\Result;
use Throwable;

/**
 * A filter paired with its evaluated comparison value — the value each
 * filter's {@see Filter::$value} source produced once, before the row loop.
 */
final readonly class ResolvedFilter
{
    public function __construct(
        public Filter $filter,
        public mixed $value,
    ) {}

    /**
     * @return Result<bool, Throwable>
     */
    public function matches(CsvRecord $record): Result
    {
        return $this->filter->matches($record, $this->value);
    }
}
