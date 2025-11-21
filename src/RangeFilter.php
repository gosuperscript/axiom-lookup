<?php

declare(strict_types=1);

namespace Superscript\Schema\Lookup\Sources;

use Superscript\Schema\Source;

/**
 * Represents a range-based filter for banding scenarios
 */
final readonly class RangeFilter
{
    public function __construct(
        public string|int $minColumn,
        public string|int $maxColumn,
        public Source $value,
    ) {}
}
