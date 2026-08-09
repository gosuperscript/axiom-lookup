<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Results;

/** The discoverable vocabulary of lookup result operations. */
enum LookupResultKind: string
{
    case First = 'first';
    case Last = 'last';
    case All = 'all';
    case Minimum = 'min';
    case Maximum = 'max';
    case Count = 'count';
    case Sum = 'sum';
    case Average = 'avg';

    /** @return list<string> */
    public static function names(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function family(): LookupResultFamily
    {
        return match ($this) {
            self::First, self::Last, self::All, self::Minimum, self::Maximum => LookupResultFamily::Projected,
            self::Count, self::Sum, self::Average => LookupResultFamily::Numeric,
        };
    }

    public function requiresColumn(): bool
    {
        return match ($this) {
            self::Minimum, self::Maximum, self::Sum, self::Average => true,
            self::First, self::Last, self::All, self::Count => false,
        };
    }
}
