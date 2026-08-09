<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Results;

final readonly class FirstRow
{
    public function kind(): LookupResultKind
    {
        return LookupResultKind::First;
    }
}
