<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Tests\Support\Sqlite;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Superscript\Axiom\Lookup\Support\Sqlite\SqliteLookupDescription;
use Superscript\Axiom\Lookup\Support\Sqlite\SqliteLookupFormat;

/**
 * The description read directly, against hand-crafted artefacts: inside the
 * reader every refusal quietly becomes a rebuild, so the refusals
 * themselves are pinned here where they stay observable.
 */
#[CoversClass(SqliteLookupDescription::class)]
#[CoversClass(SqliteLookupFormat::class)]
final class SqliteLookupDescriptionTest extends TestCase
{
    /**
     * @param array<string, string> $meta
     * @param list<string> $header
     */
    private function craft(array $meta, array $header = []): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE _axiom_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE _axiom_header (pos INTEGER PRIMARY KEY, name TEXT NOT NULL)');

        $keys = $pdo->prepare('INSERT INTO _axiom_meta VALUES (?, ?)');

        foreach ($meta as $key => $value) {
            $keys->execute([$key, $value]);
        }

        $names = $pdo->prepare('INSERT INTO _axiom_header VALUES (?, ?)');

        foreach ($header as $position => $name) {
            $names->execute([$position, $name]);
        }

        return $pdo;
    }

    #[Test]
    public function it_refuses_bytes_without_a_description(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('File [x.csv] is not an axiom-lookup SQLite artefact.');

        SqliteLookupDescription::read(new PDO('sqlite::memory:'), 'x.csv');
    }

    #[Test]
    public function it_refuses_a_format_version_it_does_not_understand(): void
    {
        $pdo = $this->craft(['format_version' => '0']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'File [x.csv] uses lookup format version [0]; this reader understands version [1].',
        );

        SqliteLookupDescription::read($pdo, 'x.csv');
    }

    #[Test]
    public function it_refuses_an_artefact_missing_its_version(): void
    {
        $pdo = $this->craft(['has_header' => '0']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'File [x.csv] uses lookup format version [none]; this reader understands version [1].',
        );

        SqliteLookupDescription::read($pdo, 'x.csv');
    }

    #[Test]
    public function it_refuses_a_description_missing_its_source(): void
    {
        $pdo = $this->craft([
            'format_version' => '1',
            'has_header' => '0',
            'columns' => '1',
            'indexed' => '[]',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('File [x.csv] carries a malformed lookup description.');

        SqliteLookupDescription::read($pdo, 'x.csv');
    }

    #[Test]
    public function it_refuses_a_header_that_does_not_match_the_column_count(): void
    {
        $pdo = $this->craft([
            'format_version' => '1',
            'source' => '1|1',
            'has_header' => '1',
            'columns' => '2',
            'indexed' => '[]',
        ], ['name']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('File [x.csv] carries a malformed lookup description.');

        SqliteLookupDescription::read($pdo, 'x.csv');
    }

    #[Test]
    public function it_describes_a_complete_artefact(): void
    {
        $pdo = $this->craft([
            'format_version' => '1',
            'source' => '42|1000',
            'has_header' => '1',
            'columns' => '2',
            'indexed' => '[0,1]',
        ], ['name', 'age']);

        $description = SqliteLookupDescription::read($pdo, 'x.csv');

        $this->assertSame('42|1000', $description->source);
        $this->assertTrue($description->hasHeader);
        $this->assertSame(2, $description->columns);
        $this->assertSame(['name', 'age'], $description->header);
        $this->assertSame([0, 1], $description->indexed);
    }
}
