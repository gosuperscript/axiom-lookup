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
use Superscript\Axiom\Lookup\Readers\IndexedCsvLookupSourceReader;
use Superscript\Axiom\Lookup\Readers\StrategyLookupSourceReader;
use Superscript\Axiom\Lookup\Support\CsvIndexedScan;

#[CoversClass(StrategyLookupSourceReader::class)]
#[CoversClass(IndexedCsvLookupSourceReader::class)]
#[CoversClass(FullCsvScanLookupSourceReader::class)]
#[UsesClass(LookupSource::class)]
#[UsesClass(CsvIndexedScan::class)]
final class ReadersTest extends TestCase
{
    private function fixtures(): Filesystem
    {
        return new Filesystem(new LocalFilesystemAdapter(__DIR__ . '/../Fixtures'));
    }

    private function strategy(): StrategyLookupSourceReader
    {
        return new StrategyLookupSourceReader(
            new IndexedCsvLookupSourceReader($this->fixtures()),
            new FullCsvScanLookupSourceReader($this->fixtures()),
        );
    }

    #[Test]
    public function the_strategy_streams_when_no_index_is_declared_even_with_a_value(): void
    {
        $scans = [];

        $records = $this->strategy()->findRecord(
            new LookupSource(path: 'users.csv'),
            'Alice',
            function (string $scan) use (&$scans): void {
                $scans[] = $scan;
            },
        );

        $this->assertCount(5, iterator_to_array($records, false));
        $this->assertSame(['full-stream'], $scans);
    }

    #[Test]
    public function the_strategy_seeks_when_an_index_and_a_value_meet(): void
    {
        $scans = [];

        $records = $this->strategy()->findRecord(
            new LookupSource(path: 'users.csv', index: 'name'),
            'Alice',
            function (string $scan) use (&$scans): void {
                $scans[] = $scan;
            },
        );

        $rows = iterator_to_array($records, false);

        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame(['index-seek'], $scans);
    }

    #[Test]
    public function the_full_scan_reads_without_a_scan_listener(): void
    {
        $records = new FullCsvScanLookupSourceReader($this->fixtures())
            ->findRecord(new LookupSource(path: 'users.csv'), null);

        $rows = iterator_to_array($records, false);

        $this->assertCount(5, $rows);
        $this->assertSame(
            ['id' => '1', 'name' => 'Alice', 'age' => '30', 'city' => 'NYC', 'salary' => '75000'],
            $rows[0],
        );
    }

    #[Test]
    public function the_full_scan_reads_headerless_files_positionally(): void
    {
        $records = new FullCsvScanLookupSourceReader($this->fixtures())
            ->findRecord(new LookupSource(path: 'no_header.csv', hasHeader: false), null);

        $rows = iterator_to_array($records, false);

        $this->assertNotSame([], $rows);
        $this->assertArrayHasKey(0, $rows[0]);
    }

    #[Test]
    public function the_indexed_reader_finds_a_block_without_a_scan_listener(): void
    {
        $block = new IndexedCsvLookupSourceReader($this->fixtures())
            ->findBlock(new LookupSource(path: 'users.csv', index: 'name'), 'name', 'Alice');

        $this->assertNotNull($block);
        $this->assertCount(1, iterator_to_array($block, false));
    }

    #[Test]
    public function the_full_scan_closes_its_stream_when_the_reader_cannot_be_built(): void
    {
        $reader = new FullCsvScanLookupSourceReader($this->fixtures());

        $this->expectException(InvalidArgument::class);

        $reader->findRecord(new LookupSource(path: 'users.csv', delimiter: ',,'), null);
    }

    #[Test]
    public function the_full_scan_refuses_a_file_it_cannot_open(): void
    {
        $filesystem = $this->createStub(FilesystemOperator::class);
        $filesystem->method('readStream')->willReturn(false);

        $reader = new FullCsvScanLookupSourceReader($filesystem);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not open file: x.csv');

        $reader->findRecord(new LookupSource(path: 'x.csv'), null);
    }

    #[Test]
    public function the_indexed_reader_declines_a_non_seekable_stream(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);
        [$reader_end, $writer] = $pair;
        fwrite($writer, "code,value\na,1\n");
        fclose($writer);

        $filesystem = $this->createStub(FilesystemOperator::class);
        $filesystem->method('readStream')->willReturn($reader_end);

        $block = new IndexedCsvLookupSourceReader($filesystem)
            ->findBlock(new LookupSource(path: 'x.csv', index: 'code'), 'code', 'a');

        $this->assertNull($block);
    }

    #[Test]
    public function the_indexed_reader_declines_a_file_shape_the_scan_rules_out(): void
    {
        // users.csv has no 'postcode' column, so the scan itself declines.
        $block = new IndexedCsvLookupSourceReader($this->fixtures())
            ->findBlock(new LookupSource(path: 'users.csv', index: 'postcode'), 'postcode', 'SW1A');

        $this->assertNull($block);
    }

    #[Test]
    public function the_indexed_reader_refuses_a_file_it_cannot_open(): void
    {
        $filesystem = $this->createStub(FilesystemOperator::class);
        $filesystem->method('readStream')->willReturn(false);

        $reader = new IndexedCsvLookupSourceReader($filesystem);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not open file: x.csv');

        $reader->findBlock(new LookupSource(path: 'x.csv', index: 'code'), 'code', 'a');
    }
}
