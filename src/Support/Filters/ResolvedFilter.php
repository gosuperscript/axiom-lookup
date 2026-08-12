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
     * seek, paired with the resolved value to seek there. Null when the
     * filter is not probe-eligible, or when the value did not resolve to
     * the raw string the byte-equality domain requires — both halves must
     * hold, so they are decided here, together, once.
     *
     * @return array{int|string, string}|null
     */
    public function probe(): ?array
    {
        return $this->probeColumn !== null && is_string($this->value)
            ? [$this->probeColumn, $this->value]
            : null;
    }
}
