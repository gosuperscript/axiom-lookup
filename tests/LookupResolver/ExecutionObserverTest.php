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
use Superscript\Axiom\Lookup\LookupExtension;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Support\Aggregates;
use Superscript\Axiom\Lookup\Support\Filters\CompiledFilter;
use Superscript\Axiom\Lookup\Support\Filters\ResolvedFilter;
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Lookup\Tests\Fixtures\SpyObserver;
use Superscript\Axiom\Sources\StaticSource;

#[CoversClass(LookupExtension::class)]
#[CoversClass(LookupSource::class)]
#[UsesClass(CsvRecord::class)]
#[UsesClass(ValueFilter::class)]
#[UsesClass(CompiledFilter::class)]
#[UsesClass(ResolvedFilter::class)]
#[UsesClass(Aggregates\First::class)]
#[UsesClass(Aggregates\AggregateFactory::class)]
#[UsesClass(\Superscript\Axiom\Lookup\Support\Aggregates\AggregateKind::class)]
#[UsesClass(\Superscript\Axiom\Lookup\Readers\FullCsvScanLookupSourceReader::class)]
#[UsesClass(\Superscript\Axiom\Lookup\Readers\SqliteLookupSourceReader::class)]
#[UsesClass(\Superscript\Axiom\Lookup\Readers\StrategyLookupSourceReader::class)]
#[UsesClass(\Superscript\Axiom\Lookup\Support\Sqlite\SqliteSidecar::class)]
#[UsesClass(\Superscript\Axiom\Lookup\Support\Sqlite\SqliteLookupConverter::class)]
#[UsesClass(\Superscript\Axiom\Lookup\Support\Sqlite\SqliteLookupFormat::class)]
#[UsesClass(\Superscript\Axiom\Lookup\Support\Sqlite\SqliteLookupDescription::class)]
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
    private function lookup(
        string $aggregate = 'first',
        array $columns = ['age'],
        string|int|null $index = null,
    ): LookupSource {
        return new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('name', new StaticSource('Alice'))],
            columns: $columns,
            aggregate: $aggregate,
            index: $index,
        );
    }

    #[Test]
    public function it_annotates_label_with_source_path(): void
    {
        $this->execute($this->lookup());

        $this->assertSame('users.csv', $this->observer->annotations['label']);
    }

    #[Test]
    public function it_annotates_aggregate(): void
    {
        $this->execute($this->lookup(aggregate: 'first'));

        $this->assertSame('first', $this->observer->annotations['aggregate']);
    }

    #[Test]
    public function it_annotates_columns_when_not_empty(): void
    {
        $this->execute($this->lookup(columns: ['age', 'city']));

        $this->assertSame(['age', 'city'], $this->observer->annotations['columns']);
    }

    #[Test]
    public function it_does_not_annotate_columns_when_empty(): void
    {
        $this->execute($this->lookup(columns: []));

        $this->assertArrayNotHasKey('columns', $this->observer->annotations);
    }

    #[Test]
    public function it_annotates_the_scan_that_answered(): void
    {
        $this->execute($this->lookup());

        $this->assertSame('full-stream', $this->observer->annotations['scan']);
    }

    #[Test]
    public function an_equality_on_the_declared_index_reaches_the_sidecar_probe(): void
    {
        $this->execute($this->lookup(index: 'name'));

        $this->assertSame('sqlite-index', $this->observer->annotations['scan']);
    }

    #[Test]
    public function the_index_probe_survives_other_filters_in_front_of_it(): void
    {
        // The indexed filter is deliberately not the first: every equality
        // contributes its probe, and the sidecar picks the one it can serve.
        $this->execute(new LookupSource(
            path: 'users.csv',
            filters: [
                new ValueFilter('city', new StaticSource('NYC')),
                new ValueFilter('name', new StaticSource('Alice')),
            ],
            columns: ['age'],
            index: 'name',
        ));

        $this->assertSame('sqlite-index', $this->observer->annotations['scan']);
    }

    #[Test]
    public function it_attributes_annotations_to_the_lookup_source(): void
    {
        $this->execute($this->lookup());

        $annotations = array_values(array_filter(
            $this->observer->annotated,
            fn($event): bool => $event->node->sourceType === LookupSource::class,
        ));

        $this->assertCount(4, $annotations);
        $this->assertSame(['aggregate', 'columns', 'scan', 'label'], array_column($annotations, 'key'));
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
        $this->assertSame('30', $result->unwrap()->unwrap());
    }
}
