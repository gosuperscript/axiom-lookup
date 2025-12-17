<?php

declare(strict_types=1);

namespace Superscript\Schema\Lookup\Support\Aggregates;

use RuntimeException;
use Superscript\Schema\Lookup\CsvRecord;

final readonly class Sum implements Aggregate
{
    private function __construct(
        private float $sum,
        private bool $hasValues,
    ) {}

    public static function initial(): self
    {
        return new self(0.0, false);
    }

    public function process(CsvRecord $record, string|int|null $aggregateColumn): self
    {
        if ($aggregateColumn === null) {
            throw new RuntimeException("aggregateColumn is required when using 'sum' aggregate");
        }

        $value = $record->getNumeric($aggregateColumn);
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
