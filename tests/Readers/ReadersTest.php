<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Tests\Readers;

use League\Csv\InvalidArgument;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Readers\FullCsvScanLookupSourceReader;

#[CoversClass(FullCsvScanLookupSourceReader::class)]
#[UsesClass(LookupSource::class)]
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

    /**
     * @param iterable<mixed, array<int|string, mixed>> $records
     * @return list<array<int|string, mixed>>
     */
    private function rows(iterable $records): array
    {
        return iterator_to_array($records, false);
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
    public function the_full_scan_yields_every_record_regardless_of_probes(): void
    {
        $this->filesystem->write('users.csv', "name,age\nAlice,30\nBob,25\n");
        $scans = [];

        $records = new FullCsvScanLookupSourceReader($this->filesystem)->findRecords(
            new LookupSource(path: 'users.csv', index: 'name'),
            ['name' => 'Alice'],
            function (string $scan) use (&$scans): void {
                $scans[] = $scan;
            },
        );

        $this->assertCount(2, $this->rows($records));
        $this->assertSame(['full-stream'], $scans);
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
}
