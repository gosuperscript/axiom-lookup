<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Tests\Support\Sqlite;

use League\Csv\SyntaxError;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Superscript\Axiom\Lookup\Support\Sqlite\SqliteLookupConverter;
use Superscript\Axiom\Lookup\Support\Sqlite\SqliteLookupFormat;
use Superscript\Axiom\Lookup\Support\Sqlite\SqliteSidecar;

#[CoversClass(SqliteSidecar::class)]
#[UsesClass(SqliteLookupConverter::class)]
#[UsesClass(SqliteLookupFormat::class)]
final class SqliteSidecarTest extends TestCase
{
    private string $workspace;

    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/axiom-lookup-sidecar-' . uniqid();
        mkdir($this->workspace, recursive: true);
        $this->filesystem = new Filesystem(new LocalFilesystemAdapter($this->workspace));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->workspace));
    }

    /** @return list<string> */
    private function workFiles(): array
    {
        $files = glob("{$this->workspace}/axiom-sidecar-*");
        $this->assertNotFalse($files);

        return $files;
    }

    #[Test]
    public function the_sidecar_sits_beside_its_source(): void
    {
        $this->assertSame('lookups/data.csv.sqlite', SqliteSidecar::pathFor('lookups/data.csv'));
    }

    #[Test]
    public function the_fingerprint_is_the_sources_size_and_mtime(): void
    {
        $this->filesystem->write('data.csv', "name\nAlice\n");

        $expected = $this->filesystem->fileSize('data.csv') . '|' . $this->filesystem->lastModified('data.csv');

        $this->assertSame($expected, SqliteSidecar::fingerprint($this->filesystem, 'data.csv'));
    }

    #[Test]
    public function publishing_places_a_fresh_artefact_beside_the_csv(): void
    {
        $this->filesystem->write('data.csv', "name,age\nAlice,30\n");

        new SqliteSidecar()->publish(
            $this->filesystem,
            'data.csv',
            ['name'],
            workDirectory: $this->workspace,
        );

        $this->assertTrue($this->filesystem->fileExists('data.csv.sqlite'));
        $this->assertSame([], $this->workFiles());

        $pdo = new PDO('sqlite:' . $this->workspace . '/data.csv.sqlite');
        $meta = $pdo->query('SELECT key, value FROM _axiom_meta')->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->assertSame(SqliteSidecar::fingerprint($this->filesystem, 'data.csv'), $meta['source']);
        $this->assertSame('[0]', $meta['indexed']);
    }

    #[Test]
    public function publishing_cleans_its_work_file_when_the_source_refuses_to_convert(): void
    {
        $this->filesystem->write('data.csv', "name,name\nAlice,30\n");

        try {
            new SqliteSidecar()->publish(
                $this->filesystem,
                'data.csv',
                [],
                workDirectory: $this->workspace,
            );
            $this->fail('Duplicate headers should refuse to convert.');
        } catch (SyntaxError) {
            $this->assertFalse($this->filesystem->fileExists('data.csv.sqlite'));
            $this->assertSame([], $this->workFiles());
        }
    }

    #[Test]
    public function building_refuses_a_stream_it_cannot_open(): void
    {
        $filesystem = $this->createStub(FilesystemOperator::class);
        $filesystem->method('fileSize')->willReturn(10);
        $filesystem->method('lastModified')->willReturn(1000);
        $filesystem->method('readStream')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not open file: x.csv');

        new SqliteSidecar()->build($filesystem, 'x.csv', "{$this->workspace}/out.sqlite", []);
    }

    #[Test]
    public function building_reads_a_header_by_default(): void
    {
        $this->filesystem->write('data.csv', "name,age\nAlice,30\n");

        new SqliteSidecar()->build($this->filesystem, 'data.csv', "{$this->workspace}/out.sqlite", []);

        $pdo = new PDO('sqlite:' . $this->workspace . '/out.sqlite');
        $header = $pdo->query('SELECT pos, name FROM _axiom_header ORDER BY pos')->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->assertSame([0 => 'name', 1 => 'age'], $header);
    }

    #[Test]
    public function building_pins_the_fingerprint_the_caller_already_read(): void
    {
        $this->filesystem->write('data.csv', "name\nAlice\n");

        new SqliteSidecar()->build(
            $this->filesystem,
            'data.csv',
            "{$this->workspace}/out.sqlite",
            [],
            fingerprint: 'pinned|1',
        );

        $pdo = new PDO('sqlite:' . $this->workspace . '/out.sqlite');
        $meta = $pdo->query('SELECT key, value FROM _axiom_meta')->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->assertSame('pinned|1', $meta['source']);
    }

    #[Test]
    public function publishing_stages_the_build_in_the_work_directory_it_was_given(): void
    {
        $this->filesystem->write('data.csv', "name\nAlice\n");

        new SqliteSidecar()->publish(
            $this->filesystem,
            'data.csv',
            [],
            workDirectory: "{$this->workspace}/scratch",
        );

        // The staging area was claimed (and left clean) — proof the work
        // happened where the caller pointed, not in the system default.
        $this->assertDirectoryExists("{$this->workspace}/scratch");
        $files = glob("{$this->workspace}/scratch/*");
        $this->assertSame([], $files);
    }

    #[Test]
    public function publishing_cleans_its_work_file_when_the_upload_fails(): void
    {
        $this->filesystem->write('data.csv', "name\nAlice\n");

        $filesystem = $this->createStub(FilesystemOperator::class);
        $filesystem->method('fileSize')->willReturnCallback(fn(string $path): int => $this->filesystem->fileSize($path));
        $filesystem->method('lastModified')->willReturnCallback(fn(string $path): int => $this->filesystem->lastModified($path));
        $filesystem->method('readStream')->willReturnCallback(fn(string $path) => $this->filesystem->readStream($path));
        $filesystem->method('writeStream')->willThrowException(new RuntimeException('read-only storage'));

        try {
            new SqliteSidecar()->publish($filesystem, 'data.csv', [], workDirectory: $this->workspace);
            $this->fail('The upload failure should surface.');
        } catch (RuntimeException $e) {
            $this->assertSame('read-only storage', $e->getMessage());
            $this->assertSame([], $this->workFiles());
        }
    }
}
