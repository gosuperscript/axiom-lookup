<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Readers;

use Closure;
use Superscript\Axiom\Lookup\LookupSource;

/**
 * Produces the records a lookup folds over. How the file is read — a full
 * CSV stream, an indexed probe against a database artefact, anything else —
 * is this seam's concern; the caller only ever filters and aggregates what
 * comes out, so every reader must yield a superset of the records the filter
 * pipeline would have matched in a full scan, in the file's original row
 * order.
 */
interface LookupSourceReader
{
    /**
     * The records to fold for this lookup. `$probes` carries the values the
     * caller's `==` filters seek, keyed by column — a reader may use them to
     * narrow where the file is read, never what the lookup means: every
     * yielded record still passes the full filter pipeline. `$scanned`
     * reports which strategy answered (e.g. `full-stream`, or an indexed
     * reader's own label) for observability.
     *
     * @param  array<int|string, string>  $probes
     * @param  (Closure(string): void)|null  $scanned
     * @return iterable<mixed, array<int|string, mixed>>
     */
    public function findRecords(LookupSource $source, array $probes, ?Closure $scanned = null): iterable;
}
