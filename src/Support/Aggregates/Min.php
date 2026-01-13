<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Aggregates;

use RuntimeException;
use Superscript\Axiom\Lookup\CsvRecord;

final readonly class Min implements Aggregate
{
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

    public function process(CsvRecord $record, string|int|null $aggregateColumn): self
    {
        if ($aggregateColumn === null) {
            throw new RuntimeException("aggregateColumn is required when using 'min' aggregate");
        }

        $value = $record->get($aggregateColumn);
        
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
