<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Results;

final readonly class NumericResult
{
    public function __construct(public CountRows|SumColumn|AverageColumn $fold) {}

    public function kind(): LookupResultKind
    {
        return $this->fold->kind();
    }
}
