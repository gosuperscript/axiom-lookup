<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup;

use Superscript\Axiom\Lookup\Support\Filters\Filter;
use Superscript\Axiom\Source;
use Superscript\Axiom\Lookup\Support\Results\NumericResult;
use Superscript\Axiom\Lookup\Support\Results\ProjectedResult;

/**
 * A typed lookup over a delimited table, described as pure serializable data.
 * The table owns file parsing and column declarations; the result owns row
 * selection, projection, and numeric folding.
 *
 * The evaluation that actually opens the file and streams its rows lives in
 * {@see LookupExtension}, the source compiler a host registers with the
 * {@see \Superscript\Axiom\Dialect}. The {@see \League\Flysystem\FilesystemOperator}
 * the read needs is injected into that extension and captured only in the
 * compiled program, never in this description.
 *
 * Every column referenced by a filter or result must be declared by the table.
 * The compiler rejects an undeclared reference before any file is opened.
 */
final readonly class LookupSource implements Source
{
    /**
     * @param list<Filter> $filters
     */
    public function __construct(
        public DelimitedTable $table,
        public ProjectedResult|NumericResult $result,
        public array $filters = [],
    ) {}
}
