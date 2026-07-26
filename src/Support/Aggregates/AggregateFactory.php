<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Aggregates;

use RuntimeException;

/**
 * The door from a persisted aggregate name to the state that aggregates it.
 * {@see AggregateKind} is the vocabulary — ask it which names exist and what
 * each one needs; this turns one of those names into a starting state, and
 * refuses anything that is not one of them.
 */
final readonly class AggregateFactory
{
    public static function for(string $aggregate): Aggregate
    {
        $kind = AggregateKind::tryFrom($aggregate);

        if ($kind === null) {
            throw new RuntimeException("Unknown aggregate: $aggregate");
        }

        return $kind->initial();
    }
}
