<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup;

use Superscript\Axiom\Lookup\Support\Filters\Filter;
use Superscript\Axiom\Source;

/**
 * A CSV/TSV lookup, described as pure data: the file path, the filters, the
 * columns to project, and the aggregate to fold with. It is a `Source` and
 * nothing more — no filesystem, no reader, no live collaborator — so a
 * lookup tree can be persisted and loaded later unchanged.
 *
 * The evaluation that actually opens the file and streams its rows lives in
 * {@see LookupExtension}, the source compiler a host registers with the
 * {@see \Superscript\Axiom\Dialect}. The {@see \League\Flysystem\FilesystemOperator}
 * the read needs is injected into that extension and captured only in the
 * compiled program, never in this description.
 */
final readonly class LookupSource implements Source
{
    /**
     * @param array<Filter> $filters
     * @param array<string|int> $columns
     */
    public function __construct(
        public string $path,
        public array $filters = [],
        public array $columns = [],
        public string $aggregate = 'first',
        public string|int|null $aggregateColumn = null,
        public string $delimiter = ',',
        public bool $hasHeader = true,
    ) {}
}
