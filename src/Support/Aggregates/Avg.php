<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Aggregates;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use RuntimeException;
use Superscript\Axiom\Lookup\CsvRecord;

final readonly class Avg implements Aggregate
{
    private function __construct(
        private BigDecimal $sum,
        private int $count,
    ) {}

    public static function initial(): self
    {
        return new self(BigDecimal::zero(), 0);
    }

    public function process(CsvRecord $record, string|int|null $aggregateColumn): self
    {
        if ($aggregateColumn === null) {
            throw new RuntimeException("aggregateColumn is required when using 'avg' aggregate");
        }

        $value = $record->getNumeric($aggregateColumn);

        if ($value === null) {
            throw new RuntimeException("Non-numeric value encountered in column '{$aggregateColumn}' when using 'avg' aggregate");
        }

        return new self($this->sum->plus($value), $this->count + 1);
    }

    public function finalize(array|string|int $columns): mixed
    {
        if ($this->count === 0) {
            return null;
        }

        return $this->sum->dividedBy($this->count, roundingMode: RoundingMode::HALF_UP)
            ->stripTrailingZeros()
            ->toFloat();
    }

    public function canEarlyExit(): bool
    {
        return false;
    }
}
