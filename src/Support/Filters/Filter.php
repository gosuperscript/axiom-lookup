<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Filters;

use Superscript\Axiom\Source;

/**
 * A data-only row predicate description. LookupExtension compiles its value
 * Source and binds its operators from Axiom's composed dialect before any row
 * is read.
 */
interface Filter
{
    public Source $value {
        get;
    }
}
