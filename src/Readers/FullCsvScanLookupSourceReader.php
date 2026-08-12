<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Readers;

use Closure;
use Generator;
use League\Csv\Reader;
use League\Flysystem\FilesystemOperator;
use RuntimeException;
use Superscript\Axiom\Lookup\LookupSource;
use Throwable;

/**
 * The baseline reader: stream every record of the file, one at a time, in
 * O(1) memory. It is total — any well-formed lookup can be answered this
 * way — which is what lets an indexed reader serve only the lookups it
 * positively recognises and hand everything else back here, still correct.
 */
final readonly class FullCsvScanLookupSourceReader implements LookupSourceReader
{
    public function __construct(private FilesystemOperator $filesystem) {}

    public function findRecords(LookupSource $source, array $probes, ?Closure $scanned = null): iterable
    {
        $stream = $this->filesystem->readStream($source->path);

        if ($stream === false) {
            throw new RuntimeException("Could not open file: {$source->path}");
        }

        try {
            $reader = Reader::from($stream);
            $reader->setDelimiter($source->delimiter);

            if ($source->hasHeader) {
                $reader->setHeaderOffset(0);
            }

            $records = $source->hasHeader ? $reader->getRecords() : $reader->getRecords([]);
        } catch (Throwable $e) {
            fclose($stream);

            throw $e;
        }

        $scanned?->__invoke('full-stream');

        return $this->readAndClose($stream, $records);
    }

    /**
     * @param resource $stream
     * @param iterable<mixed, array<int|string, mixed>> $records
     * @return Generator<mixed, array<int|string, mixed>>
     */
    private function readAndClose($stream, iterable $records): Generator
    {
        try {
            yield from $records;
        } finally {
            fclose($stream);
        }
    }
}
