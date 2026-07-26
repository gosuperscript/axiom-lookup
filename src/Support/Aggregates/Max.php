<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Aggregates;

use Superscript\Axiom\Lookup\CsvRecord;

final readonly class Max implements Aggregate
{
    use RequiresAggregateColumn;

    /**
     * @param mixed $maxValue
     */
    private function __construct(
        private ?CsvRecord $maxRecord,
        private mixed $maxValue,
    ) {}

    public static function initial(): self
    {
        return new self(null, null);
    }

    public function kind(): AggregateKind
    {
        return AggregateKind::Max;
    }

    public function process(CsvRecord $record, string|int|null $aggregateColumn): self
    {
        $column = $this->requireColumn($aggregateColumn);

        $value = $record->get($column);

        if ($value !== null && ($this->maxValue === null || $value > $this->maxValue)) {
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
