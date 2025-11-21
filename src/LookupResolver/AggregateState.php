<?php

declare(strict_types=1);

namespace Superscript\Schema\Lookup\Resolvers\LookupResolver;

/**
 * Base interface for aggregate state value objects
 */
interface AggregateState
{
    /**
     * Process a matching record
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
