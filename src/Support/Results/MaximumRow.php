<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Results;

final readonly class MaximumRow
{
    public function __construct(public string|int $column) {}

    public function kind(): LookupResultKind
    {
        return LookupResultKind::Maximum;
    }
}
