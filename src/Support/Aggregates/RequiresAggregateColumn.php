<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Aggregates;

use RuntimeException;

/**
 * The column guard shared by the aggregates whose kind requires an
 * `aggregateColumn`. It is written once so the requirement
 * {@see AggregateKind::requiresColumn()} advertises and the refusal an
 * aggregate actually makes cannot drift apart, and so the message names the
 * kind without any aggregate spelling its own name.
 */
trait RequiresAggregateColumn
{
    abstract public function kind(): AggregateKind;

    private function requireColumn(string|int|null $aggregateColumn): string|int
    {
        if ($aggregateColumn === null) {
            throw new RuntimeException(sprintf(
                "aggregateColumn is required when using '%s' aggregate",
                $this->kind()->value,
            ));
        }

        return $aggregateColumn;
    }
}
