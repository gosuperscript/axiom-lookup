<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Aggregates;

use Superscript\Axiom\Lookup\CsvRecord;

final readonly class Sum implements Aggregate
{
    use RequiresAggregateColumn;

    private function __construct(
        private float $sum,
        private bool $hasValues,
    ) {}

    public static function initial(): self
    {
        return new self(0.0, false);
    }

    public function kind(): AggregateKind
    {
        return AggregateKind::Sum;
    }

    public function process(CsvRecord $record, string|int|null $aggregateColumn): self
    {
        $column = $this->requireColumn($aggregateColumn);

        $value = $record->getNumeric($column);
        if ($value !== null) {
            return new self($this->sum + $value, true);
        }

        return $this;
    }

    public function finalize(array|string|int $columns): mixed
    {
        return $this->hasValues ? $this->sum : null;
    }

    public function canEarlyExit(): bool
    {
        return false;
    }
}
