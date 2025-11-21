<?php

declare(strict_types=1);

namespace Superscript\Schema\Lookup\Tests\Resolvers\LookupResolver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Schema\Lookup\Resolvers\LookupResolver\CsvRecord;

#[CoversClass(CsvRecord::class)]
class CsvRecordTest extends TestCase
{
    #[Test]
    public function it_creates_record_from_array(): void
    {
        $record = CsvRecord::from(['name' => 'Alice', 'age' => '25']);
        
        self::assertInstanceOf(CsvRecord::class, $record);
    }

    #[Test]
    public function it_gets_string_value(): void
    {
        $record = CsvRecord::from(['name' => 'Alice', 'age' => 25]);
        
        self::assertSame('Alice', $record->getString('name'));
        self::assertSame('25', $record->getString('age'));
    }

    #[Test]
    public function it_returns_null_for_missing_string(): void
    {
        $record = CsvRecord::from(['name' => 'Alice']);
        
        self::assertNull($record->getString('missing'));
    }

    #[Test]
    public function it_returns_null_for_non_scalar_string(): void
    {
        $record = CsvRecord::from(['data' => ['array']]);
        
        self::assertNull($record->getString('data'));
    }

    #[Test]
    public function it_gets_numeric_value(): void
    {
        $record = CsvRecord::from(['price' => '99.99', 'count' => 5]);
        
        self::assertSame(99.99, $record->getNumeric('price'));
        self::assertSame(5.0, $record->getNumeric('count'));
    }

    #[Test]
    public function it_returns_null_for_non_numeric(): void
    {
        $record = CsvRecord::from(['name' => 'Alice']);
        
        self::assertNull($record->getNumeric('name'));
    }

    #[Test]
    public function it_returns_null_for_missing_numeric(): void
    {
        $record = CsvRecord::from(['price' => '99.99']);
        
        self::assertNull($record->getNumeric('missing'));
    }

    #[Test]
    public function it_gets_raw_value(): void
    {
        $record = CsvRecord::from(['name' => 'Alice', 'age' => 25]);
        
        self::assertSame('Alice', $record->get('name'));
        self::assertSame(25, $record->get('age'));
    }

    #[Test]
    public function it_returns_null_for_missing_raw_value(): void
    {
        $record = CsvRecord::from(['name' => 'Alice']);
        
        self::assertNull($record->get('missing'));
    }

    #[Test]
    public function it_checks_column_exists(): void
    {
        $record = CsvRecord::from(['name' => 'Alice', 'age' => 25]);
        
        self::assertTrue($record->has('name'));
        self::assertTrue($record->has('age'));
        self::assertFalse($record->has('missing'));
    }

    #[Test]
    public function it_extracts_single_column(): void
    {
        $record = CsvRecord::from(['name' => 'Alice', 'age' => 25]);
        
        self::assertSame('Alice', $record->extract('name'));
        self::assertSame(25, $record->extract('age'));
    }

    #[Test]
    public function it_extracts_multiple_columns(): void
    {
        $record = CsvRecord::from(['name' => 'Alice', 'age' => 25, 'city' => 'NYC']);
        
        $result = $record->extract(['name', 'city']);
        
        self::assertSame(['name' => 'Alice', 'city' => 'NYC'], $result);
    }

    #[Test]
    public function it_extracts_all_columns_when_empty(): void
    {
        $data = ['name' => 'Alice', 'age' => 25];
        $record = CsvRecord::from($data);
        
        self::assertSame($data, $record->extract([]));
    }

    #[Test]
    public function it_returns_null_for_missing_extracted_column(): void
    {
        $record = CsvRecord::from(['name' => 'Alice']);
        
        self::assertNull($record->extract('missing'));
    }

    #[Test]
    public function it_includes_null_for_missing_columns_in_array_extract(): void
    {
        $record = CsvRecord::from(['name' => 'Alice']);
        
        $result = $record->extract(['name', 'missing']);
        
        self::assertSame(['name' => 'Alice', 'missing' => null], $result);
    }

    #[Test]
    public function it_returns_array_representation(): void
    {
        $data = ['name' => 'Alice', 'age' => 25];
        $record = CsvRecord::from($data);
        
        self::assertSame($data, $record->toArray());
    }

    #[Test]
    public function it_handles_int_column_indices(): void
    {
        $record = CsvRecord::from([0 => 'Alice', 1 => 25]);
        
        self::assertSame('Alice', $record->getString(0));
        self::assertSame(25.0, $record->getNumeric(1));
        self::assertTrue($record->has(0));
        self::assertSame('Alice', $record->get(0));
    }
}
