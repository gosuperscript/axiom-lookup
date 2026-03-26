<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Aggregates;

use RuntimeException;
use Superscript\Axiom\Lookup\CsvRecord;

final readonly class Sole implements Aggregate
{
    private function __construct(
        private ?CsvRecord $record,
        private int $count,
    ) {}

    public static function initial(): self
    {
        return new self(null, 0);
    }

    public function process(CsvRecord $record, string|int|null $aggregateColumn): self
    {
        return new self(
            $this->record ?? $record,
            $this->count + 1,
        );
    }

    public function finalize(array|string|int $columns): mixed
    {
        return match (true) {
            $this->count === 0 => throw new RuntimeException('Expected exactly one record, but none were found.'),
            $this->count > 1 => throw new RuntimeException("Expected exactly one record, but {$this->count} were found."),
            default => $this->record?->extract($columns),
        };
    }

    public function canEarlyExit(): bool
    {
        return $this->count > 1;
    }
}
