<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Aggregates;

use Superscript\Axiom\Lookup\CsvRecord;

/**
 * Base interface for aggregate state value objects
 */
interface Aggregate
{
    /**
     * Which aggregation this state belongs to. The kind answers what the
     * aggregation is called and what it needs, so no implementation restates
     * either: `$aggregate->kind()->requiresColumn()` gives the same answer as
     * `AggregateKind::Sum->requiresColumn()` does to a caller holding only a
     * name.
     */
    public function kind(): AggregateKind;

    /**
     * Process a matching record
     *
     * Throws when the kind {@see AggregateKind::requiresColumn()} and
     * $aggregateColumn is null — there is no sum or minimum of a whole
     * record. Ask the kind to avoid the throw.
     */
    public function process(CsvRecord $record, string|int|null $aggregateColumn): self;

    /**
     * Extract the final result
     * @param array<string|int>|string|int $columns
     */
    public function finalize(array|string|int $columns): mixed;

    /**
     * Check if early exit is possible (optimization for 'first' aggregate)
     */
    public function canEarlyExit(): bool;
}
