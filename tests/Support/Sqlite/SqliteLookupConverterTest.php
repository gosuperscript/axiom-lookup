<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Tests\Support\Sqlite;

use InvalidArgumentException;
use League\Csv\SyntaxError;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Lookup\Support\Sqlite\SqliteLookupConverter;
use Superscript\Axiom\Lookup\Support\Sqlite\SqliteLookupFormat;

#[CoversClass(SqliteLookupConverter::class)]
#[CoversClass(SqliteLookupFormat::class)]
final class SqliteLookupConverterTest extends TestCase
{
    private string $destination;

    protected function setUp(): void
    {
        $this->destination = sys_get_temp_dir() . '/axiom-lookup-tests-' . uniqid() . '.sqlite';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->destination)) {
            unlink($this->destination);
        }
    }

    /**
     * @param list<int|string> $indexColumns
     */
    private function convert(
        string $csv,
        bool $hasHeader = true,
        array $indexColumns = [],
        string $delimiter = ',',
    ): PDO {
        $stream = fopen('php://temp', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, $csv);
        rewind($stream);

        try {
            new SqliteLookupConverter()->convert(
                $stream,
                $this->destination,
                delimiter: $delimiter,
                hasHeader: $hasHeader,
                indexColumns: $indexColumns,
            );
        } finally {
            fclose($stream);
        }

        return new PDO('sqlite:' . $this->destination);
    }

    /**
     * @return array<string, string>
     */
    private function meta(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT key, value FROM _axiom_meta')->fetchAll(PDO::FETCH_KEY_PAIR);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function records(PDO $pdo): array
    {
        return $pdo->query('SELECT * FROM records ORDER BY rn')->fetchAll(PDO::FETCH_ASSOC);
    }

    #[Test]
    public function it_stores_records_in_file_order_under_a_header(): void
    {
        $pdo = $this->convert("name,age\nAlice,30\nBob,25\nCara,41\n", indexColumns: ['name']);

        $meta = $this->meta($pdo);
        $this->assertSame(SqliteLookupFormat::VERSION, $meta['format_version']);
        $this->assertSame('', $meta['source']);
        $this->assertSame('1', $meta['has_header']);
        $this->assertSame('2', $meta['columns']);
        $this->assertSame('[0]', $meta['indexed']);

        $header = $pdo->query('SELECT pos, name FROM _axiom_header ORDER BY pos')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame([0 => 'name', 1 => 'age'], $header);

        $this->assertSame([
            ['rn' => 1, 'c0' => 'Alice', 'c1' => '30'],
            ['rn' => 2, 'c0' => 'Bob', 'c1' => '25'],
            ['rn' => 3, 'c0' => 'Cara', 'c1' => '41'],
        ], $this->records($pdo));
    }

    #[Test]
    public function it_keeps_leagues_padding_and_slicing_under_a_header(): void
    {
        $pdo = $this->convert("a,b,c\n1,2,3\n4,5\n6,7,8,9\n");

        $this->assertSame([
            ['rn' => 1, 'c0' => '1', 'c1' => '2', 'c2' => '3'],
            ['rn' => 2, 'c0' => '4', 'c1' => '5', 'c2' => null],
            ['rn' => 3, 'c0' => '6', 'c1' => '7', 'c2' => '8'],
        ], $this->records($pdo));
    }

    #[Test]
    public function it_widens_for_a_ragged_headerless_file(): void
    {
        $pdo = $this->convert("1,2,3\n4,5\n6,7,8,9\n", hasHeader: false);

        $meta = $this->meta($pdo);
        $this->assertSame('0', $meta['has_header']);
        $this->assertSame('4', $meta['columns']);

        $this->assertSame(
            [],
            $pdo->query('SELECT pos, name FROM _axiom_header')->fetchAll(PDO::FETCH_KEY_PAIR),
        );

        $this->assertSame([
            ['rn' => 1, 'c0' => '1', 'c1' => '2', 'c2' => '3', 'c3' => null],
            ['rn' => 2, 'c0' => '4', 'c1' => '5', 'c2' => null, 'c3' => null],
            ['rn' => 3, 'c0' => '6', 'c1' => '7', 'c2' => '8', 'c3' => '9'],
        ], $this->records($pdo));
    }

    #[Test]
    public function it_indexes_named_and_positional_columns_once_each(): void
    {
        $pdo = $this->convert("a,b\n1,2\n", indexColumns: ['b', 1, 'a']);

        $indexes = $pdo
            ->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name LIKE 'ax_idx_%' ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN);

        $this->assertSame(['ax_idx_c0', 'ax_idx_c1'], $indexes);
        $this->assertSame('[1,0]', $this->meta($pdo)['indexed']);
    }

    #[Test]
    public function it_refuses_an_index_name_missing_from_the_header(): void
    {
        try {
            $this->convert("a,b\n1,2\n", indexColumns: ['postcode']);
            $this->fail('An unknown index column should refuse to convert.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Index column [postcode] is not part of the header.', $e->getMessage());

            // This failure strikes after the artefact file exists, so the
            // cleanup in convert() is what keeps the partial from leaking.
            $this->assertFileDoesNotExist($this->destination);
        }
    }

    #[Test]
    public function it_refuses_an_index_name_when_the_file_has_no_header(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Index column [a] is not part of the header.');

        $this->convert("1,2\n", hasHeader: false, indexColumns: ['a']);
    }

    #[Test]
    public function it_refuses_an_index_position_beyond_the_file(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Index position [2] does not exist; the file has 2 columns.');

        $this->convert("a,b\n1,2\n", indexColumns: [2]);
    }

    #[Test]
    public function it_refuses_a_negative_index_position(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Index position [-1] does not exist; the file has 2 columns.');

        $this->convert("a,b\n1,2\n", indexColumns: [-1]);
    }

    #[Test]
    public function it_overwrites_an_existing_destination(): void
    {
        file_put_contents($this->destination, 'stale bytes');

        $pdo = $this->convert("a\nfresh\n");

        $this->assertSame([['rn' => 1, 'c0' => 'fresh']], $this->records($pdo));
    }

    #[Test]
    public function it_removes_the_partial_artefact_when_the_source_is_malformed(): void
    {
        try {
            $this->convert("a,a\n1,2\n");
            $this->fail('Duplicate headers should refuse to convert.');
        } catch (SyntaxError) {
            $this->assertFileDoesNotExist($this->destination);
        }
    }

    #[Test]
    public function it_converts_an_empty_headerless_file(): void
    {
        $pdo = $this->convert('', hasHeader: false);

        $this->assertSame('0', $this->meta($pdo)['columns']);
        $this->assertSame([], $this->records($pdo));
    }

    #[Test]
    public function it_keeps_raw_bytes_and_the_empty_cell_null_distinction(): void
    {
        $latin = "\xE9t\xE9";
        $pdo = $this->convert("a,b\n{$latin},\n1\n");

        $this->assertSame([
            ['rn' => 1, 'c0' => $latin, 'c1' => ''],
            ['rn' => 2, 'c0' => '1', 'c1' => null],
        ], $this->records($pdo));
    }

    #[Test]
    public function it_parses_with_the_declared_delimiter(): void
    {
        $pdo = $this->convert("a;b\n1;2\n", delimiter: ';');

        $this->assertSame([['rn' => 1, 'c0' => '1', 'c1' => '2']], $this->records($pdo));
    }

    #[Test]
    public function it_reads_a_header_by_default(): void
    {
        $stream = fopen('php://temp', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, "a,b\n1,2\n");
        rewind($stream);

        new SqliteLookupConverter()->convert($stream, $this->destination);
        fclose($stream);

        $pdo = new PDO('sqlite:' . $this->destination);
        $header = $pdo->query('SELECT pos, name FROM _axiom_header ORDER BY pos')->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->assertSame([0 => 'a', 1 => 'b'], $header);
    }

    #[Test]
    public function it_keeps_the_header_width_even_with_no_records(): void
    {
        $pdo = $this->convert("a,b\n");

        $this->assertSame('2', $this->meta($pdo)['columns']);
        $this->assertSame([], $this->records($pdo));
    }

    #[Test]
    public function it_remembers_the_source_fingerprint_it_was_built_from(): void
    {
        $stream = fopen('php://temp', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, "a\n1\n");
        rewind($stream);

        new SqliteLookupConverter()->convert($stream, $this->destination, sourceFingerprint: '42|1000');
        fclose($stream);

        $pdo = new PDO('sqlite:' . $this->destination);

        $this->assertSame('42|1000', $this->meta($pdo)['source']);
    }

    #[Test]
    public function it_strips_the_byte_order_mark_like_the_scan_does(): void
    {
        $pdo = $this->convert("\u{FEFF}a,b\n1,2\n", indexColumns: ['a']);

        $header = $pdo->query('SELECT pos, name FROM _axiom_header ORDER BY pos')->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->assertSame([0 => 'a', 1 => 'b'], $header);
    }
}
