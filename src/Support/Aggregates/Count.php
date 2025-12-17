<?php

declare(strict_types=1);

namespace Superscript\Schema\Lookup\Support\Aggregates;

use Superscript\Schema\Lookup\CsvRecord;

final readonly class Count implements Aggregate
{
    private function __construct(
        private int $count,
    ) {}

    public static function initial(): self
    {
        return new self(0);
    }

    public function process(CsvRecord $record, string|int|null $aggregateColumn): self
    {
        return new self($this->count + 1);
    }

    public function finalize(array|string|int $columns): mixed
    {
        return $this->count > 0 ? $this->count : null;
    }

    public function canEarlyExit(): bool
    {
        return false;
    }
}
