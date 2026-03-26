<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Aggregates;

use Brick\Math\BigDecimal;
use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Axiom\Lookup\LookupException;

final readonly class Sum implements Aggregate
{
    private function __construct(
        private BigDecimal $sum,
        private bool $hasValues,
    ) {}

    public static function initial(): self
    {
        return new self(BigDecimal::zero(), false);
    }

    public function process(CsvRecord $record, string|int|null $aggregateColumn): self
    {
        if ($aggregateColumn === null) {
            throw LookupException::undefinedAggregateColumn('sum');
        }

        $value = $record->getNumeric($aggregateColumn);

        if ($value === null) {
            throw LookupException::nonNumericValue($aggregateColumn, 'sum');
        }

        return new self($this->sum->plus($value), true);
    }

    public function finalize(array|string|int $columns): mixed
    {
        return $this->hasValues ? $this->sum->toFloat() : null;
    }

    public function canEarlyExit(): bool
    {
        return false;
    }
}
