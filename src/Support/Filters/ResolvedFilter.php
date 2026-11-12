<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Filters;

use Closure;
use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Monads\Result\Result;
use Throwable;

/**
 * A dialect-bound row predicate paired with the value its Source produced
 * once, before the row loop. It also carries the column that value may be
 * sought on — the two halves of a probe travel together, exposed only
 * through {@see probe()}, so no caller has to re-pair them.
 */
final readonly class ResolvedFilter
{
    public function __construct(
        private mixed $value,
        /** @var Closure(CsvRecord, mixed): Result<bool, Throwable> */
        private Closure $matches,
        /** The column an indexed reader may seek this value on; null when the filter is not probe-eligible. */
        private string|int|null $probeColumn = null,
    ) {}

    /**
     * @return Result<bool, Throwable>
     */
    public function matches(CsvRecord $record): Result
    {
        return ($this->matches)($record, $this->value);
    }

    /**
     * The probe this filter contributes: the column an indexed reader may
     * seek, paired with the values to seek there — a row is a candidate when
     * its cell equals any of them. An equality's value is one string; a
     * membership's is the list of strings a cell may equal, and an empty list
     * seeks nothing, since no cell is a member of it. Null when the filter is
     * not probe-eligible, or when the value did not resolve to the raw strings
     * the byte-equality domain requires — both halves must hold, so they are
     * decided here, together, once.
     *
     * @return array{int|string, list<string>}|null
     */
    public function probe(): ?array
    {
        if ($this->probeColumn === null) {
            return null;
        }

        if (is_string($this->value)) {
            return [$this->probeColumn, [$this->value]];
        }

        if (!is_array($this->value) || !array_is_list($this->value)) {
            return null;
        }

        $values = [];

        foreach ($this->value as $element) {
            if (!is_string($element)) {
                return null;
            }

            $values[$element] = $element;
        }

        return [$this->probeColumn, array_values($values)];
    }
}
