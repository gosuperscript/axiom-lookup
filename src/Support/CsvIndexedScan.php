<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support;

use Generator;
use Superscript\Axiom\Lookup\LookupExtension;
use Superscript\Axiom\Lookup\LookupSource;

/**
 * A byte-offset binary search over a CSV sorted by one column, yielding only
 * the contiguous block of records whose index column equals the target.
 *
 * The scan is an I/O strategy, not a filter: {@see LookupExtension}
 * re-applies every filter to each yielded record, so the scan may only narrow
 * where the file is read — never what a lookup means. Files must honour the
 * contract documented on {@see LookupSource::$index}:
 * rows sorted by the index column in byte order (`strcmp`), one record per line.
 *
 * `records()` returns null when the file's shape rules the seek out (missing
 * or duplicated header names, a named index on a headerless file, an empty
 * file); the caller falls back to the ordinary full stream, which computes
 * the same result on such files.
 */
final readonly class CsvIndexedScan
{
    private const string UTF8_BOM = "\xEF\xBB\xBF";

    /**
     * @param  positive-int  $window  Stop bisecting below this many bytes and scan the rest linearly.
     */
    public function __construct(private int $window = 4096) {}

    /**
     * @param  resource  $stream  A seekable stream over the whole file; the scan owns its cursor.
     * @return Generator<int, array<string|int, string|null>>|null
     */
    public function records(
        $stream,
        string $delimiter,
        bool $hasHeader,
        string|int $index,
        string $target,
    ): ?Generator {
        rewind($stream);

        if (fread($stream, 3) !== self::UTF8_BOM) {
            rewind($stream);
        }

        $header = null;

        if ($hasHeader) {
            $line = fgets($stream);

            if ($line === false) {
                return null; // empty file: let the streaming reader speak for it
            }

            $header = str_getcsv($line, $delimiter, '"', '\\');

            if (in_array(null, $header, true)) {
                return null; // blank header line: not a shape we can address
            }

            // Header names key the records with PHP's array-key coercion
            // ('0' becomes 0), so resolve the index position through the same
            // coercion the shaped records will apply.
            $positions = array_combine($header, array_keys($header));

            if (count($positions) !== count($header)) {
                return null; // duplicated header names: the streaming reader owns that diagnosis
            }

            $position = $positions[$index] ?? null;

            if ($position === null) {
                return null; // no such column: no seek target, and no row could match anyway
            }
        } elseif (is_int($index)) {
            $position = $index;
        } else {
            return null; // headerless records are positional; a name cannot address them
        }

        return $this->block($stream, (int) ftell($stream), $header, $position, $delimiter, $target);
    }

    /**
     * Bisect [start, end-of-file) down to a small window, then scan it
     * linearly: skip keys before the target, yield the equal block, stop at
     * the first greater key.
     *
     * The bisection is a lower bound over byte offsets. A probe seeks into
     * the middle of a line, discards the rest of it, and reads the key of
     * the next full line. The invariant: $lower advances to a probed offset
     * only when that key sorts STRICTLY before the target, which guarantees
     * the block's first row always starts beyond the line containing $lower
     * — so the final scan, which discards one partial line after seeking
     * $lower, can never discard a block row. Equality must not advance
     * $lower: with duplicate keys the probed line may sit inside the block
     * itself, and advancing onto it would put that discard inside the block
     * and silently drop its first rows. A probe that cannot read a key at
     * all (end of file, a blank or short line) pulls $upper down instead,
     * for the same reason.
     *
     * $upper only decides when the bisection stops, never what the scan may
     * read: the linear phase ends on the first key past the target, not on
     * an offset. A misjudged probe therefore only widens the linearly
     * scanned window — it cannot hide a row.
     *
     * @param  resource  $stream
     * @param  list<string>  $header
     * @return Generator<int, array<string|int, string|null>>
     */
    private function block(
        $stream,
        int $start,
        ?array $header,
        int $position,
        string $delimiter,
        string $target,
    ): Generator {
        fseek($stream, 0, SEEK_END);
        $lower = $start;
        $upper = (int) ftell($stream);

        while ($upper - $lower > $this->window) {
            $probe = intdiv($lower + $upper, 2);
            fseek($stream, $probe);
            fgets($stream); // discard the line the seek landed in; the next read starts a full line

            if ($this->keyBefore($stream, $position, $delimiter, $target)) {
                $lower = $probe;
            } else {
                $upper = $probe;
            }
        }

        fseek($stream, $lower);

        if ($lower > $start) {
            fgets($stream); // align to a line start, exactly as the probes did
        }

        while (($line = fgets($stream)) !== false) {
            $cells = str_getcsv($line, $delimiter, '"', '\\');
            $key = $cells[$position] ?? null;

            if ($key === null) {
                continue; // blank or short line: unordered, and no filter could match it
            }

            $comparison = strcmp($key, $target);

            if ($comparison < 0) {
                continue;
            }

            if ($comparison > 0) {
                break; // sorted: the block cannot resume past a greater key
            }

            yield $header === null ? $cells : $this->shape($header, $cells);
        }
    }

    /**
     * Whether the next full line's key sorts strictly before the target.
     * End of file and unreadable keys sort after, so the search closes in on
     * them from below.
     *
     * @param  resource  $stream
     */
    private function keyBefore($stream, int $position, string $delimiter, string $target): bool
    {
        $line = fgets($stream);

        if ($line === false) {
            return false;
        }

        $cells = str_getcsv($line, $delimiter, '"', '\\');
        $key = $cells[$position] ?? null;

        return $key !== null && strcmp($key, $target) < 0;
    }

    /**
     * Shape a positional row the way the streaming reader shapes records
     * against a header: missing cells become null, surplus cells are dropped.
     *
     * @param  list<string>  $header
     * @param  list<string|null>  $cells
     * @return array<string|int, string|null>
     */
    private function shape(array $header, array $cells): array
    {
        $count = count($header);

        return array_combine($header, array_pad(array_slice($cells, 0, $count), $count, null));
    }
}
