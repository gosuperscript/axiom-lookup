<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Aggregates;

use Brick\Math\BigDecimal;
use RuntimeException;
use Superscript\Axiom\Lookup\CsvRecord;

final readonly class Sum implements Aggregate
{
    private function __construct(
        private BigDecimal $sum,
        private bool $hasValues,
    ) {}

    public static function initial(): self
    {
        return new self(BigDecimal::zero(), false);
    }

    public function process(CsvRecord $record, string|int|null $aggregateColumn): self
    {
        if ($aggregateColumn === null) {
            throw new RuntimeException("aggregateColumn is required when using 'sum' aggregate");
        }

        $value = $record->getNumeric($aggregateColumn);

        if ($value === null) {
            throw new RuntimeException("Non-numeric value encountered in column '{$aggregateColumn}' when using 'sum' aggregate");
        }

        return new self($this->sum->plus($value), true);
    }

    public function finalize(array|string|int $columns): mixed
    {
        return $this->hasValues ? $this->sum->toFloat() : null;
    }

    public function canEarlyExit(): bool
    {
        return false;
    }
}
