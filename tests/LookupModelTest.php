<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\BoundOperation;
use Superscript\Axiom\Lookup\Column;
use Superscript\Axiom\Lookup\DelimitedTable;
use Superscript\Axiom\Lookup\Support\Results\AllRows;
use Superscript\Axiom\Lookup\Support\Results\AverageColumn;
use Superscript\Axiom\Lookup\Support\Results\CompiledLookupResult;
use Superscript\Axiom\Lookup\Support\Results\CountRows;
use Superscript\Axiom\Lookup\Support\Results\FirstRow;
use Superscript\Axiom\Lookup\Support\Results\LastRow;
use Superscript\Axiom\Lookup\Support\Results\LookupResultFamily;
use Superscript\Axiom\Lookup\Support\Results\LookupResultKind;
use Superscript\Axiom\Lookup\Support\Results\MaximumRow;
use Superscript\Axiom\Lookup\Support\Results\MinimumRow;
use Superscript\Axiom\Lookup\Support\Results\NumericResult;
use Superscript\Axiom\Lookup\Support\Results\ProjectedResult;
use Superscript\Axiom\Lookup\Support\Results\RecordProjection;
use Superscript\Axiom\Lookup\Support\Results\SumColumn;
use Superscript\Axiom\Lookup\Support\Results\ValueProjection;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\UnknownType;

#[CoversClass(Column::class)]
#[CoversClass(DelimitedTable::class)]
#[CoversClass(AllRows::class)]
#[CoversClass(AverageColumn::class)]
#[CoversClass(CompiledLookupResult::class)]
#[CoversClass(CountRows::class)]
#[CoversClass(FirstRow::class)]
#[CoversClass(LastRow::class)]
#[CoversClass(LookupResultFamily::class)]
#[CoversClass(LookupResultKind::class)]
#[CoversClass(MaximumRow::class)]
#[CoversClass(MinimumRow::class)]
#[CoversClass(NumericResult::class)]
#[CoversClass(ProjectedResult::class)]
#[CoversClass(RecordProjection::class)]
#[CoversClass(SumColumn::class)]
#[CoversClass(ValueProjection::class)]
final class LookupModelTest extends TestCase
{
    #[Test]
    public function columns_require_concrete_types(): void
    {
        $column = new Column('score', new NumberType());

        $this->assertSame('score', $column->identity);

        $this->expectException(InvalidArgumentException::class);
        new Column('anything', new UnknownType());
    }

    #[Test]
    public function tables_find_named_and_positional_declarations(): void
    {
        $named = new Column('name', new StringType());
        $position = new Column(0, new StringType());
        $namedTable = new DelimitedTable('named.csv', [$named]);
        $positionalTable = new DelimitedTable('positional.csv', [$position], hasHeader: false);

        $this->assertSame($named, $namedTable->declaration('name'));
        $this->assertNull($namedTable->declaration('missing'));
        $this->assertSame($position, $positionalTable->declaration(0));
        $this->assertSame($named, $namedTable->requireDeclaration('name'));

        try {
            $namedTable->requireDeclaration('missing');
            self::fail('A missing compiled declaration was accepted.');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
    }

    #[Test]
    public function a_header_table_rejects_positional_declarations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DelimitedTable('table.csv', [new Column(0, new StringType())]);
    }

    #[Test]
    public function a_headerless_table_rejects_named_declarations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DelimitedTable('table.csv', [new Column('name', new StringType())], hasHeader: false);
    }

    #[Test]
    public function a_table_rejects_duplicate_declarations(): void
    {
        $unique = new DelimitedTable('table.csv', [
            new Column('first', new StringType()),
            new Column('second', new StringType()),
            new Column('third', new StringType()),
        ]);
        $this->assertCount(3, $unique->columns);

        $this->expectException(InvalidArgumentException::class);
        new DelimitedTable('table.csv', [
            new Column('name', new StringType()),
            new Column('score', new NumberType()),
            new Column('name', new NumberType()),
        ]);
    }

    #[Test]
    public function record_projections_are_non_empty_and_well_formed(): void
    {
        $projection = new RecordProjection(['label' => 'name', 'score' => 2]);

        $this->assertSame(['label' => 'name', 'score' => 2], $projection->fields);

        try {
            new RecordProjection([]);
            self::fail('An empty projection was accepted.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        try {
            new RecordProjection([0 => 'name']);
            self::fail('A positional result field was accepted.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        new RecordProjection(['field' => new \stdClass()]);
    }

    #[Test]
    public function result_kinds_are_discoverable_metadata(): void
    {
        $this->assertSame(
            ['first', 'last', 'all', 'min', 'max', 'count', 'sum', 'avg'],
            LookupResultKind::names(),
        );

        foreach (LookupResultKind::cases() as $kind) {
            $expectedFamily = in_array($kind, [
                LookupResultKind::First,
                LookupResultKind::Last,
                LookupResultKind::All,
                LookupResultKind::Minimum,
                LookupResultKind::Maximum,
            ], strict: true)
                ? LookupResultFamily::Projected
                : LookupResultFamily::Numeric;
            $expectedColumn = in_array($kind, [
                LookupResultKind::Minimum,
                LookupResultKind::Maximum,
                LookupResultKind::Sum,
                LookupResultKind::Average,
            ], strict: true);

            $this->assertSame($expectedFamily, $kind->family());
            $this->assertSame($expectedColumn, $kind->requiresColumn());
        }
    }

    #[Test]
    public function result_objects_report_their_kind(): void
    {
        $value = new ValueProjection('value');
        $results = [
            new ProjectedResult(new FirstRow(), $value),
            new ProjectedResult(new LastRow(), $value),
            new ProjectedResult(new AllRows(), $value),
            new ProjectedResult(new MinimumRow('value'), $value),
            new ProjectedResult(new MaximumRow('value'), $value),
            new NumericResult(new CountRows()),
            new NumericResult(new SumColumn('value')),
            new NumericResult(new AverageColumn('value')),
        ];

        $this->assertSame(LookupResultKind::names(), array_map(
            static fn(ProjectedResult|NumericResult $result): string => $result->kind()->value,
            $results,
        ));

        $ordering = new BoundOperation(new ResolvedOperation(new BooleanType(), static fn(): bool => true));
        $compiled = new CompiledLookupResult(new NumberType(), $ordering);
        $this->assertInstanceOf(NumberType::class, $compiled->returns);
        $this->assertSame($ordering, $compiled->requireOrdering());

        $this->expectException(\LogicException::class);
        (new CompiledLookupResult(new NumberType()))->requireOrdering();
    }
}
