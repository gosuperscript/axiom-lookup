<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Filters;

use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Axiom\Operators\DefaultOverloader;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Operators\OverloaderManager;
use Superscript\Axiom\Source;
use Superscript\Monads\Result\Result;

final readonly class ValueFilter implements Filter
{
    public function __construct(
        public string|int $column,
        public Source $value,
        public string $operator = '==',
    ) {}

    public function matches(CsvRecord $record, mixed $value): bool
    {
        $result = $this->getOperatorOverloader()->evaluate(
            $record->get($this->column),
            $value,
            $this->operator
        );

        return (bool) ($result instanceof Result ? $result->unwrap() : $result);
    }

    private function getOperatorOverloader(): OperatorOverloader
    {
        return new OverloaderManager([
            new DefaultOverloader(),
        ]);
    }
}
