<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Readers;

use Closure;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PDO;
use PDOStatement;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Support\Sqlite\SqliteLookupDescription;
use Superscript\Axiom\Lookup\Support\Sqlite\SqliteLookupFormat;
use Superscript\Axiom\Lookup\Support\Sqlite\SqliteSidecar;
use Throwable;

/**
 * Answers an indexed lookup from the CSV's SQLite sidecar. The freshest
 * artefact wins, wherever it came from: a sidecar published beside the CSV
 * is downloaded into the instance-local cache; a missing, stale or corrupt
 * one is rebuilt there on the fly. Rebuilds only ever write to local disk —
 * the read path never needs (or uses) write access to shared storage.
 *
 * Freshness is the source fingerprint embedded at build time: an artefact
 * that does not match the CSV as it is right now is discarded and rebuilt,
 * so a republished file, a torn download, or a future format version all
 * converge on the same behaviour — build again from the CSV, which remains
 * the only source of truth.
 *
 * Probes narrow the read to the indexed block, but never decide meaning:
 * every returned record still passes the caller's full filter pipeline, and
 * rows come back in original file order (`ORDER BY rn`) so first/last/all
 * fold exactly as a full scan would. When any of this fails — no driver,
 * unreadable storage, a build error — the reader declines with null and the
 * caller falls back to the full scan: the sidecar may only ever change how
 * fast a lookup answers, never whether it answers.
 *
 * That decline has to be decided before a single record reaches the caller,
 * which is why the rows are read in full rather than streamed: once part of
 * a result has been folded there is no falling back, only double counting.
 * The read is bounded by what the probe matched, not by the file.
 */
final readonly class SqliteLookupSourceReader
{
    private FilesystemOperator $cache;

    private string $cacheDirectory;

    public function __construct(
        private FilesystemOperator $filesystem,
        ?string $cacheDirectory = null,
        private SqliteSidecar $sidecar = new SqliteSidecar(),
    ) {
        $temp = sys_get_temp_dir();
        $this->cacheDirectory = $cacheDirectory ?? "{$temp}/axiom-lookup";
        $this->cache = new Filesystem(new LocalFilesystemAdapter($this->cacheDirectory));
    }

    /**
     * @param  array<int|string, string>  $probes
     * @param  (Closure(string): void)|null  $scanned
     * @return list<array<int|string, string|null>>|null
     */
    public function findRecords(LookupSource $source, array $probes, ?Closure $scanned = null): ?array
    {
        try {
            $fingerprint = SqliteSidecar::fingerprint($this->filesystem, $source->path);
            [$pdo, $description] = $this->locate($source, $fingerprint);

            [$conditions, $bindings] = $this->push($probes, $description);

            $columns = $description->columns === 0
                ? SqliteLookupFormat::ROW_COLUMN
                : implode(', ', array_map(SqliteLookupFormat::column(...), range(0, $description->columns - 1)));

            $sql = sprintf(
                'SELECT %s FROM %s%s ORDER BY %s',
                $columns,
                SqliteLookupFormat::RECORDS_TABLE,
                $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions),
                SqliteLookupFormat::ROW_COLUMN,
            );

            $statement = $pdo->prepare($sql);
            assert($statement instanceof PDOStatement);

            $statement->execute($bindings);

            /** @var list<list<string|null>> $rows */
            $rows = $statement->fetchAll(PDO::FETCH_NUM);
        } catch (Throwable) {
            // The CSV is still the source of truth; the full scan answers.
            return null;
        }

        $scanned?->__invoke($conditions === [] ? 'sqlite-scan' : 'sqlite-index');

        return array_map($description->record(...), $rows);
    }

    /**
     * The freshest artefact for this exact version of the CSV: the local
     * cache if it already holds one, a published sidecar if a fresh one is
     * beside the file, a local on-the-fly build otherwise. Stale and corrupt
     * candidates are simply discarded — they are derived data; the rebuild
     * is the recovery.
     *
     * @return array{PDO, SqliteLookupDescription}
     */
    private function locate(LookupSource $source, string $fingerprint): array
    {
        $key = sha1("{$source->path}|{$fingerprint}");
        $local = "{$this->cacheDirectory}/{$key}";

        if (! file_exists($local)) {
            $this->fetch($source->path, $key);
        }

        if (file_exists($local)) {
            $opened = $this->open($local, $source->path);

            // A stale or corrupt candidate needs no cleanup: the rebuild
            // below moves a fresh artefact over it.
            if ($opened !== null && $opened[1]->source === $fingerprint) {
                return $opened;
            }
        }

        $worker = getmypid();
        $partial = "{$key}-{$worker}.build";

        $this->sidecar->build(
            $this->filesystem,
            $source->path,
            "{$this->cacheDirectory}/{$partial}",
            $source->index === null ? [] : [$source->index],
            $source->delimiter,
            $source->hasHeader,
            $fingerprint,
        );
        $this->cache->move($partial, $key);

        // A just-built artefact must speak for itself; if it cannot, the
        // exception reaches findRecords' catch and the scan answers.
        $pdo = $this->connect($local);

        return [$pdo, SqliteLookupDescription::read($pdo, $source->path)];
    }

    /**
     * Pull a published sidecar into the local cache, atomically: the
     * download lands beside its final name and only a completed copy is
     * moved into place, so a concurrent worker either wins the same move
     * with identical bytes or reads a finished file, never a partial one.
     */
    private function fetch(string $path, string $key): void
    {
        $published = SqliteSidecar::pathFor($path);

        if (! $this->filesystem->fileExists($published)) {
            return;
        }

        $stream = $this->filesystem->readStream($published);
        $worker = getmypid();
        $partial = "{$key}-{$worker}.download";

        $this->cache->writeStream($partial, $stream);
        fclose($stream);
        $this->cache->move($partial, $key);
    }

    /**
     * Open a cached artefact and let it identify itself; null means the
     * bytes are not a lookup artefact this reader trusts — corrupt, torn,
     * or a format version it does not know — and the caller rebuilds.
     *
     * @return array{PDO, SqliteLookupDescription}|null
     */
    private function open(string $local, string $path): ?array
    {
        try {
            $pdo = $this->connect($local);

            return [$pdo, SqliteLookupDescription::read($pdo, $path)];
        } catch (Throwable) {
            return null;
        }
    }

    private function connect(string $local): PDO
    {
        $pdo = new PDO('sqlite:' . $local);
        $pdo->exec('PRAGMA query_only = ON');

        return $pdo;
    }

    /**
     * The probes the artefact can actually serve: an `==` value for a column
     * that resolves to an indexed position. Anything else is skipped — the
     * filter pipeline still applies every filter, so skipping only costs
     * narrowing, never correctness.
     *
     * @param  array<int|string, string>  $probes
     * @return array{list<string>, list<string>}
     */
    private function push(array $probes, SqliteLookupDescription $description): array
    {
        $conditions = [];
        $bindings = [];

        foreach ($probes as $column => $value) {
            $position = $description->position($column);

            if ($position !== null && in_array($position, $description->indexed)) {
                $conditions[] = sprintf('%s = ?', SqliteLookupFormat::column($position));
                $bindings[] = $value;
            }
        }

        return [$conditions, $bindings];
    }
}
