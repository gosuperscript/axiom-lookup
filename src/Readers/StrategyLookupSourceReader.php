<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Readers;

use Closure;
use Superscript\Axiom\Lookup\LookupSource;

/**
 * Picks the cheapest reader that can serve a lookup: the indexed seek when
 * the source declares a sorted index and the caller resolved a value to
 * seek, the full stream otherwise — or whenever the indexed reader declines.
 * Both choices produce the records the filter pipeline would have met in a
 * full scan, so the choice is invisible to results.
 */
final readonly class StrategyLookupSourceReader implements LookupSourceReader
{
    public function __construct(
        private IndexedCsvLookupSourceReader $indexed,
        private FullCsvScanLookupSourceReader $fullScan,
    ) {}

    public function findRecord(LookupSource $source, ?string $value, ?Closure $scanned = null): iterable
    {
        if ($value !== null && $source->index !== null) {
            $block = $this->indexed->findBlock($source, $source->index, $value, $scanned);

            if ($block !== null) {
                return $block;
            }
        }

        return $this->fullScan->findRecord($source, $value, $scanned);
    }
}
