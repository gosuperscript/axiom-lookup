<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Readers;

use Closure;
use Superscript\Axiom\Lookup\LookupSource;

/**
 * Picks the cheapest reader that can serve a lookup: the SQLite sidecar
 * when the source declares an index and this invocation actually seeks a
 * value on it, the full CSV stream otherwise — or whenever the sidecar
 * reader declines. Both choices produce the records the filter pipeline
 * would have met in a full scan, so the choice is invisible to results.
 */
final readonly class StrategyLookupSourceReader implements LookupSourceReader
{
    public function __construct(
        private SqliteLookupSourceReader $sidecar,
        private FullCsvScanLookupSourceReader $fullScan,
    ) {}

    public function findRecords(LookupSource $source, array $probes, ?Closure $scanned = null): iterable
    {
        // Without a probe on the declared index the sidecar cannot narrow
        // anything, and on a cold cache it would trigger a build for no
        // benefit — the stream is the cheaper total answer.
        if ($source->index !== null && isset($probes[$source->index])) {
            $records = $this->sidecar->findRecords($source, $probes, $scanned);

            if ($records !== null) {
                return $records;
            }
        }

        return $this->fullScan->findRecords($source, $probes, $scanned);
    }
}
