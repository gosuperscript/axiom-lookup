<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Filters;

use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Monads\Result\Result;

final readonly class ResolvedFilter
{
    public function __construct(
        public Filter $filter,
        public mixed $value,
    ) {}

    /** @return Result<bool, \Throwable> */
    public function matches(CsvRecord $record, OperatorOverloader $operatorOverloader): Result
    {
        return $this->filter->matches($record, $this->value, $operatorOverloader);
    }
}
