<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Filters;

use Closure;
use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Axiom\SourceEvaluation;
use Superscript\Monads\Result\Result;
use Throwable;

/**
 * A compiled value source paired with its dialect-bound row predicate, and
 * with the column an indexed reader may seek that value on. Probe eligibility
 * is a static fact — the operator, and the column's declared type — so it is
 * settled here once, not re-derived from the source on every invocation.
 */
final readonly class CompiledFilter
{
    /** @param Closure(CsvRecord, mixed): Result<bool, Throwable> $matches */
    public function __construct(
        private CompiledSource $value,
        private Closure $matches,
        private string|int|null $probeColumn = null,
    ) {}

    public function resolve(SourceEvaluation $evaluation): ResolvedFilter
    {
        return new ResolvedFilter(
            $evaluation->value($this->value),
            $this->matches,
            $this->probeColumn,
        );
    }
}
