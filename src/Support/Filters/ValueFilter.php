<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Filters;

use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Source;
use Superscript\Monads\Result\Result;

final readonly class ValueFilter implements Filter
{
    public function __construct(
        public string|int $column,
        public Source $value,
        private OperatorOverloader $operatorOverloader,
        public string $operator = '==',
    ) {}

    /** @return Result<bool, \Throwable> */
    public function matches(CsvRecord $record, mixed $value): Result
    {
        return $this->operatorOverloader->evaluate(
            $record->get($this->column),
            $value,
            $this->operator
        )->map(fn (mixed $result): bool => (bool) $result);
    }
}
