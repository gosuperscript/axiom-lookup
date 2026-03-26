<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Aggregates;

use Brick\Math\BigDecimal;
use RuntimeException;
use Superscript\Axiom\Lookup\CsvRecord;

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
            throw new RuntimeException("aggregateColumn is required when using 'max' aggregate");
        }

        $value = $record->getNumeric($aggregateColumn);

        if ($value === null) {
            throw new RuntimeException("Non-numeric value encountered in column '{$aggregateColumn}' when using 'max' aggregate");
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
