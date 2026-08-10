<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Sqlite;

use InvalidArgumentException;
use League\Csv\Reader;
use PDO;
use Superscript\Axiom\Lookup\LookupSource;
use Throwable;

/**
 * Writes the SQLite artefact a {@see LookupSource}
 * can point at instead of a CSV. The conversion is meant for publish time —
 * the moment a catalogue file is ingested — so every problem it can detect
 * is a loud throw here rather than a quiet misread later.
 *
 * The CSV is parsed with the exact calls the full-scan reader makes, so the
 * stored records are the records a scan would have yielded: short rows under
 * a header keep League's null padding, long rows keep its slicing, and a
 * headerless file may stay ragged — the table simply widens as wider rows
 * arrive, and NULL marks the cells a row never had.
 *
 * The write itself favours a fast, disposable build: no journal, no fsync,
 * one transaction, and the indexes created only after the bulk insert. A
 * crash cannot corrupt anything durable because the artefact only becomes
 * real when this method returns; on failure the partial file is removed.
 */
final readonly class SqliteLookupConverter
{
    /**
     * @param  resource  $csv  A readable stream of the source CSV.
     * @param  list<int|string>  $indexColumns  Header names (or zero-based
     *                                          positions) to index for `==` probes. Positions are the only
     *                                          address a headerless file has.
     */
    public function convert(
        $csv,
        string $destination,
        string $delimiter = ',',
        bool $hasHeader = true,
        array $indexColumns = [],
        string $sourceFingerprint = '',
    ): void {
        if (file_exists($destination)) {
            unlink($destination);
        }

        try {
            $this->build($csv, $destination, $delimiter, $hasHeader, $indexColumns, $sourceFingerprint);
        } catch (Throwable $e) {
            if (file_exists($destination)) {
                unlink($destination);
            }

            throw $e;
        }
    }

    /**
     * @param  resource  $csv
     * @param  list<int|string>  $indexColumns
     */
    private function build(
        $csv,
        string $destination,
        string $delimiter,
        bool $hasHeader,
        array $indexColumns,
        string $sourceFingerprint,
    ): void {
        $reader = Reader::from($csv);
        $reader->setDelimiter($delimiter);

        if ($hasHeader) {
            $reader->setHeaderOffset(0);
        }

        $records = $hasHeader ? $reader->getRecords() : $reader->getRecords([]);
        $header = $hasHeader ? array_values($reader->getHeader()) : null;

        $pdo = new PDO('sqlite:' . $destination);
        $pdo->exec('PRAGMA journal_mode = OFF');
        $pdo->exec('PRAGMA synchronous = OFF');
        $pdo->beginTransaction();

        // A header names every column up front, so the table is created
        // whole in one statement. Only a headerless file discovers its
        // width as rows stream by, widening one column at a time below.
        $pdo->exec(sprintf(
            'CREATE TABLE %s (%s INTEGER PRIMARY KEY%s)',
            SqliteLookupFormat::RECORDS_TABLE,
            SqliteLookupFormat::ROW_COLUMN,
            $header === null ? '' : implode('', array_map(
                static fn(int $position): string => sprintf(', %s TEXT', SqliteLookupFormat::column($position)),
                array_keys($header),
            )),
        ));

        $width = $header === null ? 0 : count($header);
        $insert = null;
        $widen = function (int $target) use ($pdo, &$width, &$insert): void {
            while ($width < $target) {
                $pdo->exec(sprintf(
                    'ALTER TABLE %s ADD COLUMN %s TEXT',
                    SqliteLookupFormat::RECORDS_TABLE,
                    SqliteLookupFormat::column($width),
                ));
                $width++;
                // The row shape changed; the next insert must be re-prepared.
                $insert = null;
            }
        };

        $row = 0;

        foreach ($records as $record) {
            /** @var list<string|null> $cells */
            $cells = array_values($record);
            $widen(count($cells));

            if ($insert === null) {
                $insert = $pdo->prepare(sprintf(
                    'INSERT INTO %s VALUES (?%s)',
                    SqliteLookupFormat::RECORDS_TABLE,
                    str_repeat(', ?', $width),
                ));
            }

            $parameters = [++$row];

            for ($position = 0; $position < $width; $position++) {
                $parameters[] = $cells[$position] ?? null;
            }

            $insert->execute($parameters);
        }

        $indexed = $this->indexPositions($indexColumns, $header, $width);

        foreach ($indexed as $position) {
            $pdo->exec(sprintf(
                'CREATE INDEX %s ON %s (%s)',
                SqliteLookupFormat::index($position),
                SqliteLookupFormat::RECORDS_TABLE,
                SqliteLookupFormat::column($position),
            ));
        }

        $this->writeDescription($pdo, $hasHeader, $header, $width, $indexed, $sourceFingerprint);

        $pdo->commit();
    }

    /**
     * Resolve the caller's index columns to table positions, loudly: an
     * unknown header name or an out-of-range position is a publish-time
     * mistake, never something to skip over.
     *
     * @param  list<int|string>  $indexColumns
     * @param  list<string>|null  $header
     * @return list<int>
     */
    private function indexPositions(array $indexColumns, ?array $header, int $width): array
    {
        $positions = [];

        foreach ($indexColumns as $column) {
            if (is_string($column)) {
                $position = $header === null ? false : array_search($column, $header, true);

                if ($position === false) {
                    throw new InvalidArgumentException(sprintf(
                        'Index column [%s] is not part of the header.',
                        $column,
                    ));
                }
            } else {
                $position = $column;
            }

            if ($position < 0 || $position >= $width) {
                throw new InvalidArgumentException(sprintf(
                    'Index position [%d] does not exist; the file has %d columns.',
                    $position,
                    $width,
                ));
            }

            $positions[$position] = $position;
        }

        return array_values($positions);
    }

    /**
     * @param  list<string>|null  $header
     * @param  list<int>  $indexed
     */
    private function writeDescription(
        PDO $pdo,
        bool $hasHeader,
        ?array $header,
        int $width,
        array $indexed,
        string $sourceFingerprint,
    ): void {
        $pdo->exec(sprintf(
            'CREATE TABLE %s (key TEXT PRIMARY KEY, value TEXT NOT NULL)',
            SqliteLookupFormat::META_TABLE,
        ));

        $meta = $pdo->prepare(sprintf(
            'INSERT INTO %s VALUES (?, ?)',
            SqliteLookupFormat::META_TABLE,
        ));

        $meta->execute(['format_version', SqliteLookupFormat::VERSION]);
        $meta->execute(['source', $sourceFingerprint]);
        $meta->execute(['has_header', $hasHeader ? '1' : '0']);
        $meta->execute(['columns', $width]);
        $meta->execute(['indexed', json_encode($indexed, JSON_THROW_ON_ERROR)]);

        $pdo->exec(sprintf(
            'CREATE TABLE %s (pos INTEGER PRIMARY KEY, name TEXT NOT NULL)',
            SqliteLookupFormat::HEADER_TABLE,
        ));

        if ($header === null) {
            return;
        }

        $names = $pdo->prepare(sprintf(
            'INSERT INTO %s VALUES (?, ?)',
            SqliteLookupFormat::HEADER_TABLE,
        ));

        foreach ($header as $position => $name) {
            $names->execute([$position, $name]);
        }
    }
}
