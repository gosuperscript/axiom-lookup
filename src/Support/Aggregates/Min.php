<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Aggregates;

use Brick\Math\BigDecimal;
use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Axiom\Lookup\LookupException;

final readonly class Min implements Aggregate
{
    private function __construct(
        private ?CsvRecord $minRecord,
        private ?BigDecimal $minValue,
    ) {}

    public static function initial(): self
    {
        return new self(null, null);
    }

    public function process(CsvRecord $record, string|int|null $aggregateColumn): self
    {
        if ($aggregateColumn === null) {
            throw LookupException::undefinedAggregateColumn('min');
        }

        $value = $record->getNumeric($aggregateColumn);

        if ($value === null) {
            throw LookupException::nonNumericValue($aggregateColumn, 'min');
        }

        if ($this->minValue === null || $value->isLessThan($this->minValue)) {
            return new self($record, $value);
        }

        return $this;
    }

    public function finalize(array|string|int $columns): mixed
    {
        return $this->minRecord?->extract($columns);
    }

    public function canEarlyExit(): bool
    {
        return false;
    }
}
