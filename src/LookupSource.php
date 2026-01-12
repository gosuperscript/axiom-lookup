<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup;

use Superscript\Axiom\Lookup\Support\Filters\Filter;
use Superscript\Schema\Source;

final readonly class LookupSource implements Source
{
    /**
     * @param string $filePath
     * @param array<Filter> $filters
     * @param array<string|int> $columns
     * @param string $aggregate
     * @param string|int|null $aggregateColumn
     * @param string $delimiter
     * @param bool $hasHeader
     */
    public function __construct(
        public string $filePath,
        public array $filters = [],
        public array $columns = [],
        public string $aggregate = 'first',
        public string|int|null $aggregateColumn = null,
        public string $delimiter = ',',
        public bool $hasHeader = true,
    ) {}
}
