<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Results;

final readonly class AllRows
{
    public function kind(): LookupResultKind
    {
        return LookupResultKind::All;
    }
}
