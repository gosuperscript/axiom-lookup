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
 *
 * Probes are byte-equality seeks. Narrowing by one is only a superset when
 * the dialect's `String == String` is raw byte equality (the core rule); a
 * host whose dialect overloads that comparison — case folding, trimming —
 * must not serve probes from an index built on bytes, or rows the pipeline
 * would have matched never arrive. The package cannot verify which rule a
 * dialect bound, so this precondition rests with the host that injects an
 * indexed reader.
 *
 * A reader may fail eagerly (before returning) or lazily (a generator that
 * only touches its file on first iteration); the caller channels both into
 * the lookup's failure Result.
 */
interface LookupSourceReader
{
    /**
     * The records to fold for this lookup. `$probes` carries the values the
     * caller's `==` filters seek, keyed by column — a reader may use them to
     * narrow where the file is read, never what the lookup means: every
     * yielded record still passes the full filter pipeline. `$scanned`
     * reports which strategy answered (e.g. `full-stream`, or an indexed
     * reader's own label) for observability; report at most once, before
     * the records are exhausted — the last report is the one annotated.
     *
     * @param array<int|string, string> $probes
     * @param (Closure(string): void)|null $scanned
     * @return iterable<mixed, array<int|string, mixed>>
     */
    public function findRecords(LookupSource $source, array $probes, ?Closure $scanned = null): iterable;
}
