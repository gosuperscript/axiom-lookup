<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Readers;

use Closure;
use Superscript\Axiom\Lookup\LookupSource;

/**
 * Produces the CSV records a lookup folds over. How the file is read — a
 * full stream, an indexed seek, anything else — is this seam's concern; the
 * caller only ever filters and aggregates what comes out, so every reader
 * must yield records the filter pipeline would have met in a full scan.
 */
interface LookupSourceReader
{
    /**
     * The records to fold for this lookup. `$value` is the resolved value an
     * `==` filter seeks on the source's declared index column, when the
     * caller found one — a reader may use it to narrow where the file is
     * read, never what the lookup means. `$scanned` reports which scan
     * strategy answered (e.g. `index-seek`, `full-stream`) for observability.
     *
     * @param  (Closure(string): void)|null  $scanned
     * @return iterable<mixed, array<int|string, mixed>>
     */
    public function findRecord(LookupSource $source, ?string $value, ?Closure $scanned = null): iterable;
}
