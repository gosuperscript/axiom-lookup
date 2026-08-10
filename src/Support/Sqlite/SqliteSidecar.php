<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Sqlite;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;

/**
 * The sidecar contract: where a CSV's SQLite artefact lives, what marks it
 * as belonging to a specific version of that CSV, and how one is built.
 *
 * `publish()` is the write path — the API a host calls when it ingests a
 * CSV, placing `{path}.sqlite` beside the file so every reader can skip the
 * build. It is also the only path that writes to shared storage: the read
 * side may rebuild a sidecar on the fly, but only into its instance-local
 * cache, so lookups never need (or use) write access to the data they read.
 *
 * Freshness is a fingerprint of the source file (size and mtime) embedded
 * in the artefact at build time: a republished CSV no longer matches, and
 * the sidecar is rebuilt rather than trusted — derived data never survives
 * its source.
 */
final readonly class SqliteSidecar
{
    public function __construct(private SqliteLookupConverter $converter = new SqliteLookupConverter()) {}

    /** The artefact sits beside its source, discoverable by name alone. */
    public static function pathFor(string $path): string
    {
        return "{$path}.sqlite";
    }

    /**
     * The version identity of a source file, as cheap metadata: the sidecar
     * remembers the fingerprint it was built from, and a mismatch means the
     * CSV moved on. False rebuilds are acceptable; false hits are not, so a
     * replaced file must change its fingerprint (size or mtime).
     */
    public static function fingerprint(FilesystemOperator $filesystem, string $path): string
    {
        $size = $filesystem->fileSize($path);
        $modified = $filesystem->lastModified($path);

        return "{$size}|{$modified}";
    }

    /**
     * Build the sidecar for a CSV into a local file. `$fingerprint` lets a
     * caller pin the version label it already read; by default the source
     * is fingerprinted here, just before it is streamed.
     *
     * @param list<int|string> $indexColumns
     */
    public function build(
        FilesystemOperator $filesystem,
        string $path,
        string $destination,
        array $indexColumns,
        string $delimiter = ',',
        bool $hasHeader = true,
        ?string $fingerprint = null,
    ): void {
        $fingerprint ??= self::fingerprint($filesystem, $path);

        $stream = $filesystem->readStream($path);

        if ($stream === false) {
            throw new RuntimeException("Could not open file: {$path}");
        }

        try {
            $this->converter->convert($stream, $destination, $delimiter, $hasHeader, $indexColumns, $fingerprint);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Build and place the artefact beside its CSV — the publish-time write
     * a host performs once per file version, typically right after the CSV
     * itself lands.
     *
     * @param list<int|string> $indexColumns
     */
    public function publish(
        FilesystemOperator $filesystem,
        string $path,
        array $indexColumns,
        string $delimiter = ',',
        bool $hasHeader = true,
        ?string $workDirectory = null,
    ): void {
        $directory = $workDirectory ?? sys_get_temp_dir();
        $work = new Filesystem(new LocalFilesystemAdapter($directory));
        $worker = getmypid();
        $unique = uniqid();
        $name = "axiom-sidecar-{$worker}-{$unique}.sqlite";

        try {
            $this->build($filesystem, $path, "{$directory}/{$name}", $indexColumns, $delimiter, $hasHeader);

            $stream = $work->readStream($name);
            $filesystem->writeStream(self::pathFor($path), $stream);
            fclose($stream);
        } finally {
            if ($work->fileExists($name)) {
                $work->delete($name);
            }
        }
    }
}
