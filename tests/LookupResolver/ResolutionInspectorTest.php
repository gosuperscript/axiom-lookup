<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Tests\LookupResolver;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Support\Aggregates;
use Superscript\Axiom\Lookup\Support\Filters\ResolvedFilter;
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Lookup\Tests\Fixtures\SpyInspector;
use Superscript\Axiom\Sources\StaticSource;

#[CoversClass(LookupSource::class)]
#[UsesClass(CsvRecord::class)]
#[UsesClass(ValueFilter::class)]
#[UsesClass(ResolvedFilter::class)]
#[UsesClass(Aggregates\First::class)]
#[UsesClass(Aggregates\AggregateFactory::class)]
class ResolutionInspectorTest extends TestCase
{
    private Filesystem $filesystem;

    private SpyInspector $inspector;

    protected function setUp(): void
    {
        $this->inspector = new SpyInspector();

        $adapter = new LocalFilesystemAdapter(__DIR__ . '/../Fixtures');
        $this->filesystem = new Filesystem($adapter);
    }

    private function execute(LookupSource $source, bool $withInspector = true): void
    {
        $expression = new Expression($source, inspector: $withInspector ? $this->inspector : null);

        $expression->compile()->unwrap()();
    }

    /**
     * @param array<string|int> $columns
     */
    private function lookup(string $aggregate = 'first', array $columns = ['age']): LookupSource
    {
        return new LookupSource(
            path: 'users.csv',
            filesystem: $this->filesystem,
            filters: [new ValueFilter('name', new StaticSource('Alice'))],
            columns: $columns,
            aggregate: $aggregate,
        );
    }

    #[Test]
    public function it_annotates_label_with_source_path(): void
    {
        $this->execute($this->lookup());

        $this->assertSame('users.csv', $this->inspector->annotations['label']);
    }

    #[Test]
    public function it_annotates_aggregate(): void
    {
        $this->execute($this->lookup(aggregate: 'first'));

        $this->assertSame('first', $this->inspector->annotations['aggregate']);
    }

    #[Test]
    public function it_annotates_columns_when_not_empty(): void
    {
        $this->execute($this->lookup(columns: ['age', 'city']));

        $this->assertSame(['age', 'city'], $this->inspector->annotations['columns']);
    }

    #[Test]
    public function it_does_not_annotate_columns_when_empty(): void
    {
        $this->execute($this->lookup(columns: []));

        $this->assertArrayNotHasKey('columns', $this->inspector->annotations);
    }

    #[Test]
    public function it_works_without_inspector(): void
    {
        $source = $this->lookup();

        $result = (new Expression($source))->compile()->unwrap()();

        $this->assertTrue($result->isOk());
        $this->assertSame('30', $result->unwrap()->unwrap());
    }
}
