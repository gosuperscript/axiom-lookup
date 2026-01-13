<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Aggregates;

use Superscript\Axiom\Lookup\CsvRecord;
use function Psl\Vec\map;

final readonly class All implements Aggregate
{
    /**
     * @param  list<CsvRecord>  $records
     */
    private function __construct(
        private array $records = [],
    ) {}

    public static function initial(): self
    {
        return new self([]);
    }

    public function process(CsvRecord $record, string|int|null $aggregateColumn): self
    {
        return new self([
            ...$this->records,
            $record,
        ]);
    }

    public function finalize(array|string|int $columns): mixed
    {
        return map($this->records, fn(CsvRecord $record) => $record->extract($columns));
    }

    public function canEarlyExit(): bool
    {
        return false;
    }
}
