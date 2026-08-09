<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Results;

enum LookupResultFamily: string
{
    case Projected = 'projected';
    case Numeric = 'numeric';
}
