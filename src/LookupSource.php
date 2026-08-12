<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup;

use League\Flysystem\FilesystemOperator;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Lookup\Support\Filters\Filter;
use Superscript\Axiom\Source;
use Superscript\Axiom\Types\Type;

/**
 * A CSV/TSV lookup, described as pure data: the file path, the filters, the
 * columns to project, and the aggregate to fold with. It is a `Source` and
 * nothing more — no filesystem, no reader, no live collaborator — so a
 * lookup tree can be persisted and loaded later unchanged.
 *
 * The evaluation that actually opens the file and streams its rows lives in
 * {@see LookupExtension}, the source compiler a host registers with the
 * {@see Dialect}. The {@see FilesystemOperator}
 * the read needs is injected into that extension and captured only in the
 * compiled program, never in this description.
 */
final readonly class LookupSource implements Source
{
    /**
     * `$index` names the column `==` lookups seek on: when set, an indexed
     * {@see \Superscript\Axiom\Lookup\Readers\LookupSourceReader} may answer
     * an equality on it from an index instead of streaming the whole file.
     * Purely an access-path hint — declaring it never changes what a lookup
     * returns — and only sound where the dialect's `String == String` is raw
     * byte equality (see the reader interface's precondition).
     *
     * @param array<Filter> $filters
     * @param array<string|int> $columns
     * @param array<string|int, Type> $schema
     */
    public function __construct(
        public string $path,
        public array $filters = [],
        public array $columns = [],
        public string $aggregate = 'first',
        public string|int|null $aggregateColumn = null,
        public string $delimiter = ',',
        public bool $hasHeader = true,
        public array $schema = [],
        public string|int|null $index = null,
    ) {}
}
