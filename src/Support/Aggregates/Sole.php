<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Aggregates;

use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Axiom\Lookup\LookupException;

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
            $this->count === 0 ? $record : $this->record,
            $this->count + 1,
        );
    }

    public function finalize(array|string|int $columns): mixed
    {
        if ($this->count !== 1 || $this->record === null) {
            throw LookupException::unexpectedRowCount(1, $this->count);
        }

        return $this->record->extract($columns);
    }

    public function canEarlyExit(): bool
    {
        return $this->count > 1;
    }
}
