<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Readers;

use Closure;
use Generator;
use League\Flysystem\FilesystemOperator;
use RuntimeException;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Support\CsvIndexedScan;

/**
 * Reads only the block of records whose index column equals the sought
 * value, located by {@see CsvIndexedScan}'s byte-offset binary search. The
 * reader is partial by design: it declines — returning null so its caller
 * can stream the whole file instead — whenever the seek cannot be trusted
 * (a non-seekable stream, or a file shape the scan rules out).
 *
 * Remote object storage (an S3 bucket) hands out non-seekable streams, so a
 * host reading from one must wrap its filesystem in a read-through local
 * cache for the index to ever engage; without one, every lookup quietly
 * falls back to the full stream.
 */
final readonly class IndexedCsvLookupSourceReader
{
    public function __construct(
        private FilesystemOperator $filesystem,
        private CsvIndexedScan $scan = new CsvIndexedScan,
    ) {}

    /**
     * The block of records whose `$column` cell equals `$value`, or null
     * when this file cannot be seeked and the caller must fall back.
     *
     * @param  (Closure(string): void)|null  $scanned
     * @return Generator<int, array<string|int, string|null>>|null
     */
    public function findBlock(
        LookupSource $source,
        string|int $column,
        string $value,
        ?Closure $scanned = null,
    ): ?Generator {
        $stream = $this->filesystem->readStream($source->path);

        if ($stream === false) {
            throw new RuntimeException("Could not open file: {$source->path}");
        }

        if (! stream_get_meta_data($stream)['seekable']) {
            fclose($stream);

            return null;
        }

        $records = $this->scan->records($stream, $source->delimiter, $source->hasHeader, $column, $value);

        if ($records === null) {
            fclose($stream);

            return null;
        }

        $scanned?->__invoke('index-seek');

        return $this->readAndClose($stream, $records);
    }

    /**
     * @param  resource  $stream
     * @param  Generator<int, array<string|int, string|null>>  $records
     * @return Generator<int, array<string|int, string|null>>
     */
    private function readAndClose($stream, Generator $records): Generator
    {
        try {
            yield from $records;
        } finally {
            fclose($stream);
        }
    }
}
