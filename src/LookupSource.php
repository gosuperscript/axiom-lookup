<?php

declare(strict_types=1);

namespace Superscript\Schema\Lookup\Sources;

use Superscript\Schema\Source;

/**
 * Represents a lookup operation on CSV/TSV files.
 * 
 * @property string $filePath The path to the CSV/TSV file
 * @property string $delimiter The field delimiter (e.g., ',' for CSV, "\t" for TSV)
 * @property array<ExactFilter|RangeFilter> $filters Array of filters (exact or range-based)
 * @property array<string|int>|string|int $columns Column name(s) or index(es) to retrieve from matching rows (not used for count/sum/avg aggregates)
 * @property string $aggregate Aggregate function to apply (first, last, min, max, count, sum, avg)
 * @property string|int|null $aggregateColumn Column name or index to use for aggregation (required for min/max/sum/avg)
 * @property bool $hasHeader Whether the file has a header row
 */
final readonly class LookupSource implements Source
{
    /**
     * @param string $filePath
     * @param string $delimiter
     * @param array<ExactFilter|RangeFilter> $filters
     * @param array<string|int>|string|int $columns
     * @param string $aggregate
     * @param string|int|null $aggregateColumn
     * @param bool $hasHeader
     */
    public function __construct(
        public string $filePath,
        public string $delimiter = ',',
        public array $filters = [],
        public array|string|int $columns = [],
        public string $aggregate = 'first',
        public string|int|null $aggregateColumn = null,
        public bool $hasHeader = true,
    ) {}
}
