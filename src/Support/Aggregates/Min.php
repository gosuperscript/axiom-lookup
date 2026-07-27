<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Aggregates;

use Superscript\Axiom\Lookup\CsvRecord;

final readonly class Min implements Aggregate
{
    use RequiresAggregateColumn;

    /**
     * @param mixed $minValue
     */
    private function __construct(
        private ?CsvRecord $minRecord,
        private mixed $minValue,
    ) {}

    public static function initial(): self
    {
        return new self(null, null);
    }

    public function kind(): AggregateKind
    {
        return AggregateKind::Min;
    }

    public function process(CsvRecord $record, string|int|null $aggregateColumn): self
    {
        $column = $this->requireColumn($aggregateColumn);

        $value = $record->get($column);

        if ($value !== null && ($this->minValue === null || $value < $this->minValue)) {
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
