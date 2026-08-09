<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Tests\LookupResolver;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Axiom\Lookup\Column;
use Superscript\Axiom\Lookup\DelimitedTable;
use Superscript\Axiom\Lookup\LookupExtension;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Support\Filters\CompiledFilter;
use Superscript\Axiom\Lookup\Support\Filters\ResolvedFilter;
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Lookup\Support\Results\CountRows;
use Superscript\Axiom\Lookup\Support\Results\FirstRow;
use Superscript\Axiom\Lookup\Support\Results\NumericResult;
use Superscript\Axiom\Lookup\Support\Results\ProjectedResult;
use Superscript\Axiom\Lookup\Support\Results\RecordProjection;
use Superscript\Axiom\Lookup\Support\Results\ValueProjection;
use Superscript\Axiom\Lookup\Tests\Fixtures\SpyObserver;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;

#[CoversClass(LookupExtension::class)]
#[CoversClass(LookupSource::class)]
#[UsesClass(CsvRecord::class)]
#[UsesClass(ValueFilter::class)]
#[UsesClass(CompiledFilter::class)]
#[UsesClass(ResolvedFilter::class)]
#[UsesClass(Column::class)]
#[UsesClass(DelimitedTable::class)]
#[UsesClass(\Superscript\Axiom\Lookup\Support\Results\CompiledLookupResult::class)]
#[UsesClass(CountRows::class)]
#[UsesClass(FirstRow::class)]
#[UsesClass(NumericResult::class)]
#[UsesClass(ProjectedResult::class)]
#[UsesClass(RecordProjection::class)]
#[UsesClass(ValueProjection::class)]
final class ExecutionObserverTest extends TestCase
{
    private Filesystem $filesystem;

    private SpyObserver $observer;

    protected function setUp(): void
    {
        $this->observer = new SpyObserver();

        $adapter = new LocalFilesystemAdapter(__DIR__ . '/../Fixtures');
        $this->filesystem = new Filesystem($adapter);
    }

    private function execute(LookupSource $source, bool $withObserver = true): void
    {
        $expression = new Expression(
            $source,
            dialect: Dialect::core()->with(new LookupExtension($this->filesystem)),
        );

        $expression->compile()->unwrap()(observer: $withObserver ? $this->observer : null);
    }

    /**
     * @param array<string|int> $columns
     */
    private function lookup(bool $numeric = false, array $columns = ['age']): LookupSource
    {
        $projection = count($columns) === 1
            ? new ValueProjection($columns[0])
            : new RecordProjection(array_combine(array_map(strval(...), $columns), $columns));

        return new LookupSource(
            table: new DelimitedTable('users.csv', [
                new Column('name', new StringType()),
                new Column('age', new NumberType()),
                new Column('city', new StringType()),
            ]),
            result: $numeric
                ? new NumericResult(new CountRows())
                : new ProjectedResult(new FirstRow(), $projection),
            filters: [new ValueFilter('name', new StaticSource('Alice'))],
        );
    }

    #[Test]
    public function it_annotates_label_with_source_path(): void
    {
        $this->execute($this->lookup());

        $this->assertSame('users.csv', $this->observer->annotations['label']);
    }

    #[Test]
    public function it_annotates_result(): void
    {
        $this->execute($this->lookup());

        $this->assertSame('first', $this->observer->annotations['result']);
    }

    #[Test]
    public function it_annotates_a_record_projection(): void
    {
        $this->execute($this->lookup(columns: ['age', 'city']));

        $this->assertSame(['age' => 'age', 'city' => 'city'], $this->observer->annotations['projection']);
    }

    #[Test]
    public function it_does_not_annotate_a_projection_for_numeric_results(): void
    {
        $this->execute($this->lookup(numeric: true));

        $this->assertArrayNotHasKey('projection', $this->observer->annotations);
    }

    #[Test]
    public function it_attributes_annotations_to_the_lookup_source(): void
    {
        $this->execute($this->lookup());

        $annotations = array_values(array_filter(
            $this->observer->annotated,
            fn($event): bool => $event->node->sourceType === LookupSource::class,
        ));

        $this->assertCount(3, $annotations);
        $this->assertSame(['result', 'projection', 'label'], array_column($annotations, 'key'));
        foreach ($annotations as $event) {
            $this->assertSame(LookupSource::class, $event->node->sourceType);
        }
    }

    #[Test]
    public function it_works_without_an_observer(): void
    {
        $source = $this->lookup();

        $result = (new Expression($source, dialect: Dialect::core()->with(new LookupExtension($this->filesystem))))
            ->compile()->unwrap()();

        $this->assertTrue($result->isOk());
        $this->assertSame(30, $result->unwrap()->unwrap());
    }
}
