<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Tests\LookupResolver;

use Closure;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Axiom\Lookup\LookupExtension;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Readers\LookupSourceReader;
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
#[UsesClass(Aggregates\Count::class)]
#[UsesClass(Aggregates\AggregateFactory::class)]
#[UsesClass(\Superscript\Axiom\Lookup\Support\Aggregates\AggregateKind::class)]
#[UsesClass(\Superscript\Axiom\Lookup\Readers\FullCsvScanLookupSourceReader::class)]
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

    private function execute(
        LookupSource $source,
        bool $withObserver = true,
        ?LookupSourceReader $reader = null,
    ): void {
        $expression = new Expression(
            $source,
            dialect: Dialect::core()->with(new LookupExtension($this->filesystem, $reader)),
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
    public function an_equality_filter_hands_its_probe_to_the_injected_reader(): void
    {
        $reader = new RecordingReader();

        $this->execute($this->lookup(index: 'name'), reader: $reader);

        $this->assertSame(['name' => 'Alice'], $reader->probes);
        $this->assertSame('recorded', $this->observer->annotations['scan']);
    }

    #[Test]
    public function only_equality_filters_are_probe_eligible(): void
    {
        $reader = new RecordingReader();

        $this->execute(new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('name', new StaticSource('Alice'), '!=')],
            columns: ['age'],
            index: 'name',
        ), reader: $reader);

        $this->assertSame([], $reader->probes);
    }

    #[Test]
    public function every_string_equality_contributes_its_probe_regardless_of_order(): void
    {
        $reader = new RecordingReader();

        $this->execute(new LookupSource(
            path: 'users.csv',
            filters: [
                new ValueFilter('city', new StaticSource('NYC')),
                new ValueFilter('name', new StaticSource('Alice')),
            ],
            columns: ['age'],
            index: 'name',
        ), reader: $reader);

        $this->assertSame(['city' => 'NYC', 'name' => 'Alice'], $reader->probes);
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
    public function a_reader_failing_during_iteration_lands_in_the_failure_result(): void
    {
        // `count` folds every record, so the fold outlives the first yield
        // and meets the throw mid-iteration — after attempt() has returned.
        $source = $this->lookup(aggregate: 'count');

        $result = (new Expression(
            $source,
            dialect: Dialect::core()->with(new LookupExtension($this->filesystem, new LazilyFailingReader())),
        ))->compile()->unwrap()();

        $this->assertTrue($result->isErr());
        $this->assertSame('The file disappeared mid-read.', $result->unwrapErr()->getMessage());
    }

    #[Test]
    public function a_reader_that_never_reports_leaves_no_scan_annotation(): void
    {
        $this->execute($this->lookup(), reader: new SilentReader());

        $this->assertArrayNotHasKey('scan', $this->observer->annotations);
        $this->assertSame('users.csv', $this->observer->annotations['label']);
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

/** Captures the probes the extension derives; yields one matching record. */
final class RecordingReader implements LookupSourceReader
{
    /** @var array<int|string, string> */
    public array $probes = [];

    public function findRecords(LookupSource $source, array $probes, ?Closure $scanned = null): iterable
    {
        $this->probes = $probes;
        $scanned?->__invoke('recorded');

        return [['name' => 'Alice', 'city' => 'NYC', 'age' => '30']];
    }
}

/** Fails only once iterated — a generator body runs on advance, not on call. */
final class LazilyFailingReader implements LookupSourceReader
{
    public function findRecords(LookupSource $source, array $probes, ?Closure $scanned = null): iterable
    {
        yield ['name' => 'Alice', 'city' => 'NYC', 'age' => '30'];

        throw new RuntimeException('The file disappeared mid-read.');
    }
}

/** Yields records but never reports which strategy answered. */
final class SilentReader implements LookupSourceReader
{
    public function findRecords(LookupSource $source, array $probes, ?Closure $scanned = null): iterable
    {
        return [['name' => 'Alice', 'city' => 'NYC', 'age' => '30']];
    }
}
