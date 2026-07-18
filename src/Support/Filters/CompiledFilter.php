<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Filters;

use Closure;
use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Axiom\SourceEvaluation;
use Superscript\Monads\Result\Result;
use Throwable;

/** A compiled value source paired with its dialect-bound row predicate. */
final readonly class CompiledFilter
{
    /** @param Closure(CsvRecord, mixed): Result<bool, Throwable> $matches */
    public function __construct(
        private CompiledSource $value,
        private Closure $matches,
    ) {}

    public function resolve(SourceEvaluation $evaluation): ResolvedFilter
    {
        return new ResolvedFilter(
            $evaluation->value($this->value),
            $this->matches,
        );
    }
}
