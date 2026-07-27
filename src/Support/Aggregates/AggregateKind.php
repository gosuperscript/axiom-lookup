<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Aggregates;

/**
 * The aggregate vocabulary: which aggregations a lookup can ask for, and
 * what each one needs to run. A `LookupSource` stores its aggregate as a
 * persisted string (`'sum'`), so this enum is where that string becomes a
 * kind — and the only place the list of kinds is written down.
 *
 * It exists so callers need not restate the list. A caller validating a
 * lookup before running it, or offering a choice of aggregations, reads it
 * from here:
 *
 * ```php
 * AggregateKind::names();                  // ['first', 'last', 'count', 'sum', ...]
 * AggregateKind::Sum->requiresColumn();    // true  — offer a column picker
 * AggregateKind::Count->requiresColumn();  // false — a column would mean nothing
 * AggregateKind::Sum->initial();           // the empty Sum state to fold records into
 * ```
 *
 * Two aggregations read whole records and two read one column's values.
 * `first`, `last`, `count` and `all` need no `aggregateColumn`: they count
 * matching records or extract the requested columns from them. `sum`, `avg`,
 * `min` and `max` need one, because there is no such thing as the sum of a
 * record — {@see requiresColumn()} is that distinction, and the aggregate
 * states enforce it (see {@see RequiresAggregateColumn}) by asking their own
 * kind rather than restating the rule.
 */
enum AggregateKind: string
{
    case First = 'first';
    case Last = 'last';
    case Count = 'count';
    case Sum = 'sum';
    case Avg = 'avg';
    case Min = 'min';
    case Max = 'max';
    case All = 'all';

    /**
     * Every aggregate name a lookup may use, in declaration order.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Does an aggregation of this kind need an `aggregateColumn` naming the
     * values it reads? Answerable without an aggregate in hand, so a caller
     * can decide whether to ask for a column before there is anything to
     * aggregate.
     */
    public function requiresColumn(): bool
    {
        return match ($this) {
            self::Sum, self::Avg, self::Min, self::Max => true,
            self::First, self::Last, self::Count, self::All => false,
        };
    }

    /** The empty state an aggregation of this kind folds its matching records into. */
    public function initial(): Aggregate
    {
        return match ($this) {
            self::First => First::initial(),
            self::Last => Last::initial(),
            self::Count => Count::initial(),
            self::Sum => Sum::initial(),
            self::Avg => Avg::initial(),
            self::Min => Min::initial(),
            self::Max => Max::initial(),
            self::All => All::initial(),
        };
    }
}
