<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Aggregates;

use Brick\Math\BigDecimal;
use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Axiom\Lookup\LookupException;

final readonly class Max implements Aggregate
{
    private function __construct(
        private ?CsvRecord $maxRecord,
        private ?BigDecimal $maxValue,
    ) {}

    public static function initial(): self
    {
        return new self(null, null);
    }

    public function process(CsvRecord $record, string|int|null $aggregateColumn): self
    {
        if ($aggregateColumn === null) {
            throw LookupException::undefinedAggregateColumn('max');
        }

        $value = $record->getNumeric($aggregateColumn);

        if ($value === null) {
            throw LookupException::nonNumericValue($aggregateColumn, 'max');
        }

        if ($this->maxValue === null || $value->isGreaterThan($this->maxValue)) {
            return new self($record, $value);
        }

        return $this;
    }

    public function finalize(array|string|int $columns): mixed
    {
        return $this->maxRecord?->extract($columns);
    }

    public function canEarlyExit(): bool
    {
        return false;
    }
}
