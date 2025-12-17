<?php

declare(strict_types=1);

namespace Superscript\Schema\Lookup\Support\Filters;

use Superscript\Schema\Lookup\CsvRecord;
use Superscript\Schema\Source;

final readonly class RangeFilter implements Filter
{
    public function __construct(
        public string|int $minColumn,
        public string|int $maxColumn,
        public Source $value,
    ) {}

    public function matches(CsvRecord $record, mixed $value): bool
    {
        if (! $record->has($this->minColumn) || ! $record->has($this->maxColumn)) {
            return false;
        }

        $minValue = $record->get($this->minColumn);
        $maxValue = $record->get($this->maxColumn);

        // [min, max) range
        if (is_numeric($value) && is_numeric($minValue) && is_numeric($maxValue)) {
            return $value >= $minValue && $value < $maxValue;
        }

        return $value >= $minValue && $value < $maxValue;
    }
}
