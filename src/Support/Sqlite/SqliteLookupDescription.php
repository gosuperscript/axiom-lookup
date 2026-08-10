<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Sqlite;

use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

use function Psl\Type\dict;
use function Psl\Type\int;
use function Psl\Type\shape;
use function Psl\Type\string;
use function Psl\Type\vec;

/**
 * What a sidecar says about itself: the fingerprint of the CSV it was built
 * from, the header layout, the column count, and which columns carry an
 * index. Read once per open from the meta tables the
 * {@see SqliteLookupConverter} wrote; anything missing or unrecognisable is
 * a refusal — the reader discards the artefact and rebuilds from the CSV,
 * which remains the source of truth.
 */
final readonly class SqliteLookupDescription
{
    /**
     * @param list<string> $header
     * @param list<int> $indexed
     */
    private function __construct(
        public string $source,
        public bool $hasHeader,
        public int $columns,
        public array $header,
        public array $indexed,
    ) {}

    public static function read(PDO $pdo, string $path): self
    {
        try {
            $meta = dict(string(), string())->coerce(self::rows(
                $pdo,
                sprintf('SELECT key, value FROM %s', SqliteLookupFormat::META_TABLE),
            ));
            $names = dict(int(), string())->coerce(self::rows(
                $pdo,
                sprintf('SELECT pos, name FROM %s ORDER BY pos', SqliteLookupFormat::HEADER_TABLE),
            ));
        } catch (Throwable $e) {
            throw new RuntimeException(
                "File [{$path}] is not an axiom-lookup SQLite artefact.",
                previous: $e,
            );
        }

        $version = $meta['format_version'] ?? null;

        if ($version !== SqliteLookupFormat::VERSION) {
            throw new RuntimeException(sprintf(
                'File [%s] uses lookup format version [%s]; this reader understands version [%s].',
                $path,
                $version ?? 'none',
                SqliteLookupFormat::VERSION,
            ));
        }

        try {
            $described = shape([
                'source' => string(),
                'has_header' => string(),
                'columns' => string(),
                'indexed' => string(),
            ], allow_unknown_fields: true)->coerce($meta);

            $source = $described['source'];
            $hasHeader = $described['has_header'] === '1';
            $columns = int()->coerce($described['columns']);
            $indexed = vec(int())->coerce(json_decode($described['indexed'], flags: JSON_THROW_ON_ERROR));
            $header = array_values($names);

            if ($hasHeader && count($header) !== $columns) {
                throw new RuntimeException('The header table does not match the column count.');
            }
        } catch (Throwable $e) {
            throw new RuntimeException(
                "File [{$path}] carries a malformed lookup description.",
                previous: $e,
            );
        }

        return new self($source, $hasHeader, $columns, $header, $indexed);
    }

    /**
     * The table position an `==` probe on this column may seek, or null when
     * the column does not address a cell — skipping a probe is always safe,
     * it only means less narrowing before the filter pipeline.
     */
    public function position(int|string $column): ?int
    {
        if ($this->hasHeader) {
            $position = is_string($column) ? array_search($column, $this->header, true) : false;

            return $position === false ? null : $position;
        }

        // No bounds check: the caller only probes positions the artefact
        // actually indexed, and those are within the table by construction.
        return is_int($column) ? $column : null;
    }

    /**
     * Rebuild the record a full CSV scan would have yielded for this row:
     * under a header every column is present (NULL is League's own padding
     * of short rows); positionally a NULL cell is one the row never had, so
     * the key is omitted — exactly the ragged shape the scan yields.
     *
     * @param list<string|null> $row
     * @return array<int|string, string|null>
     */
    public function record(array $row): array
    {
        if ($this->hasHeader) {
            return array_combine($this->header, $row);
        }

        $record = [];

        foreach ($row as $position => $cell) {
            if ($cell !== null) {
                $record[$position] = $cell;
            }
        }

        return $record;
    }

    /** @return array<mixed, mixed> */
    private static function rows(PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);
        assert($statement instanceof PDOStatement);

        return $statement->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
