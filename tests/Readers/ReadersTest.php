<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Tests\Readers;

use League\Csv\InvalidArgument;
use League\Csv\SyntaxError;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Readers\FullCsvScanLookupSourceReader;
use Superscript\Axiom\Lookup\Readers\SqliteLookupSourceReader;
use Superscript\Axiom\Lookup\Readers\StrategyLookupSourceReader;
use Superscript\Axiom\Lookup\Support\Sqlite\SqliteLookupConverter;
use Superscript\Axiom\Lookup\Support\Sqlite\SqliteLookupDescription;
use Superscript\Axiom\Lookup\Support\Sqlite\SqliteLookupFormat;
use Superscript\Axiom\Lookup\Support\Sqlite\SqliteSidecar;

#[CoversClass(StrategyLookupSourceReader::class)]
#[CoversClass(SqliteLookupSourceReader::class)]
#[CoversClass(FullCsvScanLookupSourceReader::class)]
#[CoversClass(SqliteLookupDescription::class)]
#[CoversClass(SqliteSidecar::class)]
#[UsesClass(LookupSource::class)]
#[UsesClass(SqliteLookupConverter::class)]
#[UsesClass(SqliteLookupFormat::class)]
final class ReadersTest extends TestCase
{
    private string $workspace;

    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/axiom-lookup-readers-' . uniqid();
        mkdir($this->workspace, recursive: true);
        $this->filesystem = new Filesystem(new LocalFilesystemAdapter($this->workspace));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->workspace));
    }

    private function sqlite(?FilesystemOperator $filesystem = null): SqliteLookupSourceReader
    {
        return new SqliteLookupSourceReader(
            $filesystem ?? $this->filesystem,
            $this->workspace . '/cache',
        );
    }

    private function strategy(?FilesystemOperator $filesystem = null): StrategyLookupSourceReader
    {
        $filesystem ??= $this->filesystem;

        return new StrategyLookupSourceReader(
            $this->sqlite($filesystem),
            new FullCsvScanLookupSourceReader($filesystem),
        );
    }

    /**
     * @param iterable<mixed, array<int|string, mixed>> $records
     * @return list<array<int|string, mixed>>
     */
    private function rows(iterable $records): array
    {
        return iterator_to_array($records, false);
    }

    /**
     * @param list<int|string> $index
     */
    private function publish(string $csv, array $index, string $at = 'data.csv'): void
    {
        $this->filesystem->write($at, $csv);

        new SqliteSidecar()->publish(
            $this->filesystem,
            $at,
            $index,
            workDirectory: $this->workspace,
        );
    }

    #[Test]
    public function the_strategy_streams_when_no_index_is_declared_even_with_probes(): void
    {
        $this->filesystem->write('users.csv', "name,age\nAlice,30\nBob,25\n");
        $scans = [];

        $records = $this->strategy()->findRecords(
            new LookupSource(path: 'users.csv'),
            ['name' => 'Alice'],
            function (string $scan) use (&$scans): void {
                $scans[] = $scan;
            },
        );

        $this->assertCount(2, $this->rows($records));
        $this->assertSame(['full-stream'], $scans);
    }

    #[Test]
    public function the_strategy_streams_when_no_probe_targets_the_index(): void
    {
        $this->filesystem->write('users.csv', "name,age\nAlice,30\nBob,25\n");
        $scans = [];

        $records = $this->strategy()->findRecords(
            new LookupSource(path: 'users.csv', index: 'name'),
            ['age' => '30'],
            function (string $scan) use (&$scans): void {
                $scans[] = $scan;
            },
        );

        $this->assertCount(2, $this->rows($records));
        $this->assertSame(['full-stream'], $scans);
    }

    #[Test]
    public function the_strategy_probes_a_published_sidecar_when_index_and_probe_meet(): void
    {
        $this->publish("name,age\nAlice,30\nBob,25\nAlice,41\n", index: ['name']);
        $scans = [];

        $records = $this->strategy()->findRecords(
            new LookupSource(path: 'data.csv', index: 'name'),
            ['name' => 'Alice'],
            function (string $scan) use (&$scans): void {
                $scans[] = $scan;
            },
        );

        $this->assertSame([
            ['name' => 'Alice', 'age' => '30'],
            ['name' => 'Alice', 'age' => '41'],
        ], $this->rows($records));
        $this->assertSame(['sqlite-index'], $scans);
        $this->assertTrue($this->filesystem->fileExists('data.csv.sqlite'));
    }

    #[Test]
    public function the_strategy_builds_locally_when_no_sidecar_was_published(): void
    {
        $this->filesystem->write('data.csv', "name,age\nAlice,30\nBob,25\n");
        $scans = [];

        $records = $this->strategy()->findRecords(
            new LookupSource(path: 'data.csv', index: 'name'),
            ['name' => 'Bob'],
            function (string $scan) use (&$scans): void {
                $scans[] = $scan;
            },
        );

        $this->assertSame([['name' => 'Bob', 'age' => '25']], $this->rows($records));
        $this->assertSame(['sqlite-index'], $scans);

        // The read path never writes to shared storage: the on-the-fly
        // build lives only in the instance-local cache.
        $this->assertFalse($this->filesystem->fileExists('data.csv.sqlite'));
    }

    #[Test]
    public function the_strategy_surfaces_the_scans_canonical_error_for_a_missing_file(): void
    {
        // The sidecar reader declines (the file cannot be fingerprinted)
        // and the caller meets the exact error a plain CSV lookup always
        // produced — acceleration never invents a new failure mode.
        $this->expectException(UnableToReadFile::class);

        $this->strategy()->findRecords(
            new LookupSource(path: 'missing.csv', index: 'name'),
            ['name' => 'Alice'],
        );
    }

    #[Test]
    public function the_full_scan_reads_without_a_scan_listener(): void
    {
        $this->filesystem->write('users.csv', "name,age\nAlice,30\n");

        $records = new FullCsvScanLookupSourceReader($this->filesystem)
            ->findRecords(new LookupSource(path: 'users.csv'), []);

        $this->assertSame([['name' => 'Alice', 'age' => '30']], $this->rows($records));
    }

    #[Test]
    public function the_full_scan_reads_headerless_files_positionally(): void
    {
        $this->filesystem->write('plain.csv', "1,2\n3,4\n");

        $records = new FullCsvScanLookupSourceReader($this->filesystem)
            ->findRecords(new LookupSource(path: 'plain.csv', hasHeader: false), []);

        $this->assertSame([[0 => '1', 1 => '2'], [0 => '3', 1 => '4']], $this->rows($records));
    }

    #[Test]
    public function the_full_scan_closes_its_stream_when_the_reader_cannot_be_built(): void
    {
        $this->filesystem->write('users.csv', "name\nAlice\n");

        $this->expectException(InvalidArgument::class);

        new FullCsvScanLookupSourceReader($this->filesystem)
            ->findRecords(new LookupSource(path: 'users.csv', delimiter: ',,'), []);
    }

    #[Test]
    public function the_full_scan_refuses_a_stream_it_cannot_open(): void
    {
        $filesystem = $this->createStub(FilesystemOperator::class);
        $filesystem->method('readStream')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not open file: x.csv');

        new FullCsvScanLookupSourceReader($filesystem)->findRecords(new LookupSource(path: 'x.csv'), []);
    }

    #[Test]
    public function the_sidecar_reader_reuses_its_cache_and_rebuilds_per_version(): void
    {
        $csv = "name,age\nAlice,30\n";
        $size = strlen($csv);
        $modified = 1000;
        $reads = 0;

        $filesystem = $this->createStub(FilesystemOperator::class);
        $filesystem->method('fileExists')->willReturn(false);
        $filesystem->method('fileSize')->willReturnCallback(function () use (&$size): int {
            return $size;
        });
        $filesystem->method('lastModified')->willReturnCallback(function () use (&$modified): int {
            return $modified;
        });
        $filesystem->method('readStream')->willReturnCallback(function () use (&$reads, &$csv) {
            $reads++;
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $csv);
            rewind($stream);

            return $stream;
        });

        $reader = $this->sqlite($filesystem);
        $source = new LookupSource(path: 'remote.csv', index: 'name');

        $this->rows($reader->findRecords($source, ['name' => 'Alice']) ?? []);
        $this->rows($reader->findRecords($source, ['name' => 'Alice']) ?? []);
        $this->assertSame(1, $reads);

        $modified = 2000;
        $this->rows($reader->findRecords($source, ['name' => 'Alice']) ?? []);
        $this->assertSame(2, $reads);

        $size = $size + 1;
        $this->rows($reader->findRecords($source, ['name' => 'Alice']) ?? []);
        $this->assertSame(3, $reads);
    }

    #[Test]
    public function the_sidecar_reader_downloads_a_published_sidecar_instead_of_building(): void
    {
        $this->publish("name,age\nAlice,30\n", index: ['name']);
        $sidecar = $this->filesystem->read('data.csv.sqlite');
        $size = $this->filesystem->fileSize('data.csv');
        $modified = $this->filesystem->lastModified('data.csv');

        $reads = [];
        $filesystem = $this->createStub(FilesystemOperator::class);
        $filesystem->method('fileExists')->willReturn(true);
        $filesystem->method('fileSize')->willReturn($size);
        $filesystem->method('lastModified')->willReturn($modified);
        $filesystem->method('readStream')->willReturnCallback(function (string $path) use (&$reads, $sidecar) {
            $reads[] = $path;
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $sidecar);
            rewind($stream);

            return $stream;
        });

        $reader = $this->sqlite($filesystem);
        $source = new LookupSource(path: 'data.csv', index: 'name');

        $records = $reader->findRecords($source, ['name' => 'Alice']);
        $this->assertNotNull($records);
        $this->assertSame([['name' => 'Alice', 'age' => '30']], $this->rows($records));

        // Only the published artefact travelled; the CSV was never read.
        $this->assertSame(['data.csv.sqlite'], $reads);

        $this->rows($reader->findRecords($source, ['name' => 'Alice']) ?? []);
        $this->assertSame(['data.csv.sqlite'], $reads);
    }

    #[Test]
    public function the_sidecar_reader_rebuilds_a_stale_published_sidecar(): void
    {
        $this->publish("name,age\nAlice,30\n", index: ['name']);

        // The CSV moves on; the published sidecar does not.
        $this->filesystem->write('data.csv', "name,age\nAlice,31,\n");

        $records = $this->sqlite()->findRecords(
            new LookupSource(path: 'data.csv', index: 'name'),
            ['name' => 'Alice'],
        );

        $this->assertNotNull($records);
        $this->assertSame([['name' => 'Alice', 'age' => '31']], $this->rows($records));
    }

    #[Test]
    public function the_sidecar_reader_rebuilds_a_corrupt_published_sidecar(): void
    {
        $this->filesystem->write('data.csv', "name,age\nAlice,30\n");
        $this->filesystem->write('data.csv.sqlite', 'not a database at all');

        $records = $this->sqlite()->findRecords(
            new LookupSource(path: 'data.csv', index: 'name'),
            ['name' => 'Alice'],
        );

        $this->assertNotNull($records);
        $this->assertSame([['name' => 'Alice', 'age' => '30']], $this->rows($records));
    }

    #[Test]
    public function the_sidecar_reader_rebuilds_an_artefact_from_an_unknown_format_version(): void
    {
        $this->publish("name,age\nAlice,30\n", index: ['name']);
        $this->tamper("UPDATE _axiom_meta SET value = '0' WHERE key = 'format_version'");

        $records = $this->sqlite()->findRecords(
            new LookupSource(path: 'data.csv', index: 'name'),
            ['name' => 'Alice'],
        );

        $this->assertNotNull($records);
        $this->assertSame([['name' => 'Alice', 'age' => '30']], $this->rows($records));
    }

    #[Test]
    public function the_sidecar_reader_rebuilds_an_artefact_missing_part_of_its_description(): void
    {
        $this->publish("name,age\nAlice,30\n", index: ['name']);
        $this->tamper("DELETE FROM _axiom_meta WHERE key = 'columns'");

        $records = $this->sqlite()->findRecords(
            new LookupSource(path: 'data.csv', index: 'name'),
            ['name' => 'Alice'],
        );

        $this->assertNotNull($records);
        $this->assertSame([['name' => 'Alice', 'age' => '30']], $this->rows($records));
    }

    #[Test]
    public function the_sidecar_reader_rebuilds_an_artefact_whose_header_lost_a_column(): void
    {
        $this->publish("name,age\nAlice,30\n", index: ['name']);
        $this->tamper('DELETE FROM _axiom_header WHERE pos = 1');

        $records = $this->sqlite()->findRecords(
            new LookupSource(path: 'data.csv', index: 'name'),
            ['name' => 'Alice'],
        );

        $this->assertNotNull($records);
        $this->assertSame([['name' => 'Alice', 'age' => '30']], $this->rows($records));
    }

    #[Test]
    public function the_sidecar_reader_declines_when_the_source_cannot_be_fingerprinted(): void
    {
        $records = $this->sqlite()->findRecords(
            new LookupSource(path: 'missing.csv', index: 'name'),
            ['name' => 'Alice'],
        );

        $this->assertNull($records);
    }

    #[Test]
    public function the_sidecar_reader_declines_when_a_published_sidecar_cannot_be_opened(): void
    {
        $csv = "name,age\nAlice,30\n";

        $filesystem = $this->createStub(FilesystemOperator::class);
        $filesystem->method('fileExists')->willReturn(true);
        $filesystem->method('fileSize')->willReturn(strlen($csv));
        $filesystem->method('lastModified')->willReturn(1000);
        $filesystem->method('readStream')->willReturn(false);

        $records = $this->sqlite($filesystem)->findRecords(
            new LookupSource(path: 'data.csv', index: 'name'),
            ['name' => 'Alice'],
        );

        $this->assertNull($records);
    }

    #[Test]
    public function the_sidecar_reader_declines_when_the_build_itself_fails(): void
    {
        // Duplicate header names refuse to convert — and would refuse the
        // full scan identically, so declining hands the caller the same
        // canonical error the file always produced.
        $this->filesystem->write('data.csv', "name,name\nAlice,30\n");

        $records = $this->sqlite()->findRecords(
            new LookupSource(path: 'data.csv', index: 'name'),
            ['name' => 'Alice'],
        );

        $this->assertNull($records);

        $this->expectException(SyntaxError::class);

        $this->rows($this->strategy()->findRecords(
            new LookupSource(path: 'data.csv', index: 'name'),
            ['name' => 'Alice'],
        ));
    }

    #[Test]
    public function the_sidecar_reader_skips_probes_the_artefact_cannot_serve(): void
    {
        $this->publish("name,age\nAlice,30\nBob,25\n", index: ['name']);
        $scans = [];

        $records = $this->sqlite()->findRecords(
            new LookupSource(path: 'data.csv', index: 'name'),
            ['age' => '30', 0 => 'Alice', 'missing' => 'x'],
            function (string $scan) use (&$scans): void {
                $scans[] = $scan;
            },
        );

        // age carries no index; 0 does not address a headered file;
        // 'missing' is no column at all. All fall to the filter pipeline.
        $this->assertNotNull($records);
        $this->assertCount(2, $this->rows($records));
        $this->assertSame(['sqlite-scan'], $scans);
    }

    #[Test]
    public function the_sidecar_reader_probes_headerless_files_by_position(): void
    {
        $this->filesystem->write('plain.csv', "a,1\nb,2\na,3\n");

        $records = $this->sqlite()->findRecords(
            new LookupSource(path: 'plain.csv', hasHeader: false, index: 0),
            [0 => 'a', 1 => 'ignored', -1 => 'ignored', 'name' => 'ignored'],
        );

        $this->assertNotNull($records);
        $this->assertSame([
            [0 => 'a', 1 => '1'],
            [0 => 'a', 1 => '3'],
        ], $this->rows($records));
    }

    #[Test]
    public function the_sidecar_reader_matches_header_names_by_identity_not_numeric_equality(): void
    {
        // Headers '0' and '00' are distinct names; a loose match would seek
        // the wrong column and hide the rows the filters actually want.
        $this->publish("0,00\nx,y\np,q\n", index: ['0', '00']);

        $records = $this->sqlite()->findRecords(
            new LookupSource(path: 'data.csv', index: '00'),
            ['00' => 'q'],
        );

        $this->assertNotNull($records);
        $this->assertSame([['0' => 'p', '00' => 'q']], $this->rows($records));
    }

    #[Test]
    public function the_sidecar_reader_reads_an_empty_headerless_file(): void
    {
        $this->filesystem->write('empty.csv', '');

        $records = $this->sqlite()->findRecords(
            new LookupSource(path: 'empty.csv', hasHeader: false),
            [],
        );

        $this->assertNotNull($records);
        $this->assertSame([], $this->rows($records));
    }

    /**
     * Publish a valid sidecar, run one statement against it, and put the
     * tampered bytes back — the shape of a torn upload or a foreign file.
     */
    private function tamper(string $sql): void
    {
        $local = $this->workspace . '/tampered-' . uniqid() . '.sqlite';
        $bytes = $this->filesystem->read('data.csv.sqlite');
        file_put_contents($local, $bytes);

        $pdo = new PDO('sqlite:' . $local);
        $pdo->exec($sql);
        unset($pdo);

        $tampered = file_get_contents($local);
        $this->assertNotFalse($tampered);
        unlink($local);

        $this->filesystem->write('data.csv.sqlite', $tampered);
    }
}
