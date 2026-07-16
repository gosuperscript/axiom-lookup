<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Filters;

use Closure;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Axiom\Runtime;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

/** A compiled value source paired with its dialect-bound row predicate. */
final readonly class CompiledFilter
{
    /** @param Closure(CsvRecord, mixed): Result<bool, Throwable> $matches */
    public function __construct(
        private CompiledNode $value,
        private Closure $matches,
    ) {}

    /** @return Result<ResolvedFilter, Throwable> */
    public function resolve(Runtime $runtime): Result
    {
        return $this->value->evaluate($runtime)
            ->map(fn(Option $option) => new ResolvedFilter(
                $option->unwrapOr(null),
                $this->matches,
            ));
    }
}
