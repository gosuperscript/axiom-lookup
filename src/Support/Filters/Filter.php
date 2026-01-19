<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Filters;

use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Axiom\Source;

interface Filter
{
    public Source $value {get;}

    public function matches(CsvRecord $record, mixed $value): bool;
}