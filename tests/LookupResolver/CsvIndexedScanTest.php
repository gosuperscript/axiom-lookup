<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Tests\LookupResolver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Lookup\Support\CsvIndexedScan;

#[CoversClass(CsvIndexedScan::class)]
final class CsvIndexedScanTest extends TestCase
{
    /** @return resource */
    private function stream(string $content)
    {
        $stream = fopen('php://temp', 'r+b');
        $this->assertIsResource($stream);
        fwrite($stream, $content);

        return $stream;
    }

    /**
     * A sorted fixture with a known block per key: key K00 has one row,
     * K01 two, K02 three, K03 one again, and so on — so every sweep target
     * exercises blocks of varying width at varying byte offsets.
     */
    private static function sortedFixture(): string
    {
        $lines = ['code,value'];

        foreach (range(0, 39) as $i) {
            foreach (range(0, $i % 3) as $n) {
                $lines[] = sprintf('K%02d,V%02d-%d', $i, $i, $n);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /** @return list<array<string|int, string|null>> */
    private static function expectedBlock(int $i): array
    {
        $rows = [];

        foreach (range(0, $i % 3) as $n) {
            $rows[] = ['code' => sprintf('K%02d', $i), 'value' => sprintf('V%02d-%d', $i, $n)];
        }

        return $rows;
    }

    /** @return iterable<string, array{string, list<array<string|int, string|null>>, int}> */
    public static function sweepTargets(): iterable
    {
        foreach ([1, 16] as $window) {
            foreach (range(0, 39) as $i) {
                $target = sprintf('K%02d', $i);

                yield "{$target}, window {$window}" => [$target, self::expectedBlock($i), $window];
            }

            yield "before all keys, window {$window}" => ['A', [], $window];
            yield "between two keys, window {$window}" => ['K05x', [], $window];
            yield "after all keys, window {$window}" => ['Z', [], $window];
            yield "empty target, window {$window}" => ['', [], $window];
        }
    }

    /** @param list<array<string|int, string|null>> $expected */
    #[Test]
    #[DataProvider('sweepTargets')]
    public function it_yields_exactly_the_block_of_equal_keys(string $target, array $expected, int $window): void
    {
        $records = new CsvIndexedScan($window)
            ->records($this->stream(self::sortedFixture()), ',', true, 'code', $target);

        $this->assertNotNull($records);
        $this->assertSame($expected, iterator_to_array($records, false));
    }

    #[Test]
    public function the_default_window_bisects_a_file_larger_than_itself(): void
    {
        $lines = ['code,value'];

        for ($i = 0; $i < 5000; $i++) {
            $lines[] = sprintf('P%05d,%d', $i, $i * 7);
        }

        $content = implode("\n", $lines) . "\n";

        foreach ([0, 1, 2500, 4998, 4999] as $i) {
            $records = new CsvIndexedScan()
                ->records($this->stream($content), ',', true, 'code', sprintf('P%05d', $i));

            $this->assertNotNull($records);
            $this->assertSame(
                [['code' => sprintf('P%05d', $i), 'value' => (string) ($i * 7)]],
                iterator_to_array($records, false),
            );
        }

        $absent = new CsvIndexedScan()
            ->records($this->stream($content), ',', true, 'code', 'P02500x');

        $this->assertNotNull($absent);
        $this->assertSame([], iterator_to_array($absent, false));
    }

    #[Test]
    public function a_block_wider_than_the_window_is_yielded_whole(): void
    {
        $lines = ['code,value'];

        foreach (range(0, 9) as $i) {
            $lines[] = sprintf('A%02d,x', $i);
        }

        foreach (range(0, 29) as $n) {
            $lines[] = sprintf('B00,y%02d', $n);
        }

        foreach (range(0, 9) as $i) {
            $lines[] = sprintf('C%02d,z', $i);
        }

        $records = new CsvIndexedScan(8)
            ->records($this->stream(implode("\n", $lines) . "\n"), ',', true, 'code', 'B00');

        $this->assertNotNull($records);

        $rows = iterator_to_array($records, false);

        $this->assertCount(30, $rows);
        $this->assertSame(['code' => 'B00', 'value' => 'y00'], $rows[0]);
        $this->assertSame(['code' => 'B00', 'value' => 'y29'], $rows[29]);
    }

    #[Test]
    public function it_yields_positional_rows_for_headerless_files(): void
    {
        $records = new CsvIndexedScan(8)
            ->records($this->stream("a,1\nb,2\nb,3\nc,4\n"), ',', false, 0, 'b');

        $this->assertNotNull($records);
        $this->assertSame([['b', '2'], ['b', '3']], iterator_to_array($records, false));
    }

    #[Test]
    public function a_named_index_on_a_headerless_file_declines(): void
    {
        $records = new CsvIndexedScan()
            ->records($this->stream("a,1\nb,2\n"), ',', false, 'code', 'b');

        $this->assertNull($records);
    }

    #[Test]
    public function an_index_column_missing_from_the_header_declines(): void
    {
        $records = new CsvIndexedScan()
            ->records($this->stream("code,value\na,1\n"), ',', true, 'postcode', 'a');

        $this->assertNull($records);
    }

    #[Test]
    public function duplicated_header_names_decline(): void
    {
        $records = new CsvIndexedScan()
            ->records($this->stream("code,code\na,1\n"), ',', true, 'code', 'a');

        $this->assertNull($records);
    }

    #[Test]
    public function a_blank_header_line_declines(): void
    {
        $records = new CsvIndexedScan()
            ->records($this->stream("\na,1\n"), ',', true, 'code', 'a');

        $this->assertNull($records);
    }

    #[Test]
    public function an_empty_file_with_a_header_declines(): void
    {
        $records = new CsvIndexedScan()
            ->records($this->stream(''), ',', true, 'code', 'a');

        $this->assertNull($records);
    }

    #[Test]
    public function an_empty_headerless_file_yields_nothing(): void
    {
        $records = new CsvIndexedScan()
            ->records($this->stream(''), ',', false, 0, 'a');

        $this->assertNotNull($records);
        $this->assertSame([], iterator_to_array($records, false));
    }

    #[Test]
    public function crlf_line_endings_are_stripped(): void
    {
        $records = new CsvIndexedScan(8)
            ->records($this->stream("code,value\r\na,1\r\nb,2\r\nc,3\r\n"), ',', true, 'code', 'b');

        $this->assertNotNull($records);
        $this->assertSame([['code' => 'b', 'value' => '2']], iterator_to_array($records, false));
    }

    #[Test]
    public function quoted_cells_keep_embedded_delimiters(): void
    {
        $records = new CsvIndexedScan()
            ->records($this->stream("code,value\n\"a\",plain\nb,\"x,y\"\n"), ',', true, 'code', 'b');

        $this->assertNotNull($records);
        $this->assertSame([['code' => 'b', 'value' => 'x,y']], iterator_to_array($records, false));

        $quotedKey = new CsvIndexedScan()
            ->records($this->stream("code,value\n\"a\",plain\nb,\"x,y\"\n"), ',', true, 'code', 'a');

        $this->assertNotNull($quotedKey);
        $this->assertSame([['code' => 'a', 'value' => 'plain']], iterator_to_array($quotedKey, false));
    }

    #[Test]
    public function tab_delimited_files_are_supported(): void
    {
        $records = new CsvIndexedScan(8)
            ->records($this->stream("code\tvalue\na\t1\nb\t2\nc\t3\n"), "\t", true, 'code', 'b');

        $this->assertNotNull($records);
        $this->assertSame([['code' => 'b', 'value' => '2']], iterator_to_array($records, false));
    }

    #[Test]
    public function a_utf8_bom_does_not_hide_the_first_headerless_row(): void
    {
        $records = new CsvIndexedScan()
            ->records($this->stream("\xEF\xBB\xBFa,1\nb,2\n"), ',', false, 0, 'a');

        $this->assertNotNull($records);
        $this->assertSame([['a', '1']], iterator_to_array($records, false));
    }

    #[Test]
    public function a_utf8_bom_does_not_hide_the_header(): void
    {
        $records = new CsvIndexedScan()
            ->records($this->stream("\xEF\xBB\xBFcode,value\na,1\n"), ',', true, 'code', 'a');

        $this->assertNotNull($records);
        $this->assertSame([['code' => 'a', 'value' => '1']], iterator_to_array($records, false));
    }

    #[Test]
    public function blank_and_short_lines_cannot_carry_the_key(): void
    {
        // The index sits in the third cell; the short row and the blank line
        // cannot be ordered, so the scan steps over them.
        $content = "x,y,code\n1,1,a\nshort\n\n2,2,b\n3,3,c\n";

        $records = new CsvIndexedScan()
            ->records($this->stream($content), ',', true, 'code', 'b');

        $this->assertNotNull($records);
        $this->assertSame([['x' => '2', 'y' => '2', 'code' => 'b']], iterator_to_array($records, false));
    }

    #[Test]
    public function rows_are_shaped_like_streamed_records(): void
    {
        // A surplus cell is dropped, a missing cell is padded with null —
        // exactly how the streaming reader shapes records against the header.
        $content = "code,value,extra\na,1\nb,2,x,surplus\n";

        $padded = new CsvIndexedScan()
            ->records($this->stream($content), ',', true, 'code', 'a');

        $this->assertNotNull($padded);
        $this->assertSame(
            [['code' => 'a', 'value' => '1', 'extra' => null]],
            iterator_to_array($padded, false),
        );

        $sliced = new CsvIndexedScan()
            ->records($this->stream($content), ',', true, 'code', 'b');

        $this->assertNotNull($sliced);
        $this->assertSame(
            [['code' => 'b', 'value' => '2', 'extra' => 'x']],
            iterator_to_array($sliced, false),
        );
    }

    #[Test]
    public function a_probe_landing_at_end_of_file_closes_the_search_from_below(): void
    {
        // No trailing newline, and a window of one byte: the bisection parks
        // probes on the unterminated last line and beyond, where reads hit
        // end-of-file. Those probes must close the range from below — a miss
        // stays a miss, and the last row stays findable.
        $lines = ['code,value'];

        foreach (range(0, 30) as $i) {
            $lines[] = sprintf('K%02d,%d', $i, $i);
        }

        $content = implode("\n", $lines);

        $miss = new CsvIndexedScan(1)->records($this->stream($content), ',', true, 'code', 'Z');

        $this->assertNotNull($miss);
        $this->assertSame([], iterator_to_array($miss, false));

        $last = new CsvIndexedScan(1)->records($this->stream($content), ',', true, 'code', 'K30');

        $this->assertNotNull($last);
        $this->assertSame([['code' => 'K30', 'value' => '30']], iterator_to_array($last, false));
    }

    #[Test]
    public function a_probe_inside_an_oversized_last_line_still_finds_its_block(): void
    {
        // The last line dwarfs the rest of the file and has no trailing
        // newline, so the first probe lands inside it and reads end-of-file.
        // That probe must close the range from below — treating it as
        // "before the target" would start the final scan past the very row
        // the search is looking for.
        $value = str_repeat('z', 3000);
        $content = "code,value\nA00,1\nZ99,{$value}";

        $records = new CsvIndexedScan(64)
            ->records($this->stream($content), ',', true, 'code', 'Z99');

        $this->assertNotNull($records);
        $this->assertSame([['code' => 'Z99', 'value' => $value]], iterator_to_array($records, false));
    }

    #[Test]
    public function probes_landing_on_blank_lines_close_the_search_from_below(): void
    {
        // A run of blank lines wide enough to catch every probe: keys cannot
        // be read there, so the search treats them like end-of-file and the
        // final linear scan steps over them to the block.
        $content = "code,value\nA00,1\n" . str_repeat("\n", 64) . "T00,2\n";

        $records = new CsvIndexedScan(4)
            ->records($this->stream($content), ',', true, 'code', 'T00');

        $this->assertNotNull($records);
        $this->assertSame([['code' => 'T00', 'value' => '2']], iterator_to_array($records, false));
    }

    #[Test]
    public function numeric_header_names_key_records_with_php_array_coercion(): void
    {
        // A header cell '0' keys the record with the int 0, so an int index
        // addresses it — the same coercion PHP applies to the shaped record.
        $records = new CsvIndexedScan()
            ->records($this->stream("0,name\na,alpha\nb,beta\n"), ',', true, 0, 'b');

        $this->assertNotNull($records);
        $this->assertSame([[0 => 'b', 'name' => 'beta']], iterator_to_array($records, false));
    }
}
