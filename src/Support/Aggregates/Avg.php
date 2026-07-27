<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Aggregates;

use Superscript\Axiom\Lookup\CsvRecord;

final readonly class Avg implements Aggregate
{
    use RequiresAggregateColumn;

    private function __construct(
        private float $sum,
        private int $count,
    ) {}

    public static function initial(): self
    {
        return new self(0.0, 0);
    }

    public function kind(): AggregateKind
    {
        return AggregateKind::Avg;
    }

    public function process(CsvRecord $record, string|int|null $aggregateColumn): self
    {
        $column = $this->requireColumn($aggregateColumn);

        $value = $record->getNumeric($column);
        if ($value !== null) {
            return new self($this->sum + $value, $this->count + 1);
        }

        return $this;
    }

    public function finalize(array|string|int $columns): mixed
    {
        if ($this->count === 0) {
            return null;
        }

        return $this->sum / $this->count;
    }

    public function canEarlyExit(): bool
    {
        return false;
    }
}
