<?php

declare(strict_types=1);

namespace Superscript\Schema\Lookup\Support\Aggregates;

use RuntimeException;
use Superscript\Schema\Lookup\CsvRecord;

final readonly class Max implements Aggregate
{
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

    public function process(CsvRecord $record, string|int|null $aggregateColumn): self
    {
        if ($aggregateColumn === null) {
            throw new RuntimeException("aggregateColumn is required when using 'max' aggregate");
        }

        $value = $record->get($aggregateColumn);
        
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
