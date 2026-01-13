<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Aggregates;

use Superscript\Axiom\Lookup\CsvRecord;

final readonly class Last implements Aggregate
{
    private function __construct(
        private ?CsvRecord $record,
    ) {}

    public static function initial(): self
    {
        return new self(null);
    }

    public function process(CsvRecord $record, string|int|null $aggregateColumn): self
    {
        // Always keep the latest record
        return new self($record);
    }

    public function finalize(array|string|int $columns): mixed
    {
        return $this->record?->extract($columns);
    }

    public function canEarlyExit(): bool
    {
        return false;
    }
}
