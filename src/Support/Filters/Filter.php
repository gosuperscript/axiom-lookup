<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Filters;

use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Axiom\Source;
use Superscript\Monads\Result\Result;
use Throwable;

interface Filter
{
    public Source $value {get;}

    /** @return Result<bool, Throwable> */
    public function matches(CsvRecord $record, mixed $value): Result;
}