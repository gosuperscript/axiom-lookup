<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Aggregates;

use Superscript\Axiom\Lookup\LookupException;

final readonly class AggregateFactory
{
    public static function for(string $aggregate): Aggregate
    {
        return match ($aggregate) {
            'first' => First::initial(),
            'last' => Last::initial(),
            'count' => Count::initial(),
            'sum' => Sum::initial(),
            'avg' => Avg::initial(),
            'min' => Min::initial(),
            'max' => Max::initial(),
            'all' => All::initial(),
            default => throw LookupException::unknownAggregate($aggregate),
        };
    }
}