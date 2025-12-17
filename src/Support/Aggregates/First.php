<?php

declare(strict_types=1);

namespace Superscript\Schema\Lookup\Support\Aggregates;

use Superscript\Schema\Lookup\CsvRecord;

final readonly class First implements Aggregate
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
        // Keep the first record, ignore subsequent ones
        if ($this->record !== null) {
            return $this;
        }
        
        return new self($record);
    }

    public function finalize(array|string|int $columns): mixed
    {
        return $this->record?->extract($columns);
    }

    public function canEarlyExit(): bool
    {
        return $this->record !== null;
    }
}
