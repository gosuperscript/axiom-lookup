<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Tests\LookupResolver;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Context;
use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Axiom\Lookup\LookupResolver;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Support\Aggregates;
use Superscript\Axiom\Lookup\Support\Filters\ResolvedFilter;
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Lookup\Tests\Fixtures\SpyInspector;
use Superscript\Axiom\Operators\DefaultOverloader;
use Superscript\Axiom\Operators\OverloaderManager;
use Superscript\Axiom\Resolvers\DelegatingResolver;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Sources\StaticSource;

#[CoversClass(LookupResolver::class)]
#[UsesClass(LookupSource::class)]
#[UsesClass(CsvRecord::class)]
#[UsesClass(ValueFilter::class)]
#[UsesClass(ResolvedFilter::class)]
#[UsesClass(Aggregates\First::class)]
#[UsesClass(Aggregates\AggregateFactory::class)]
class ResolutionInspectorTest extends TestCase
{
    private LookupResolver $lookupResolver;

    private SpyInspector $inspector;

    protected function setUp(): void
    {
        $this->inspector = new SpyInspector();

        $overloader = new OverloaderManager([new DefaultOverloader()]);

        $delegating = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
        ]);

        $adapter = new LocalFilesystemAdapter(__DIR__.'/../Fixtures');
        $filesystem = new Filesystem($adapter);

        $this->lookupResolver = new LookupResolver(
            $filesystem,
            $delegating,
            $overloader,
        );
    }

    private function context(): Context
    {
        return new Context(inspector: $this->inspector);
    }

    #[Test]
    public function it_annotates_label_with_source_path(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('name', new StaticSource('Alice'))],
            columns: ['age'],
        );

        $this->lookupResolver->resolve($source, $this->context());

        $this->assertSame('users.csv', $this->inspector->annotations['label']);
    }

    #[Test]
    public function it_annotates_aggregate(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('name', new StaticSource('Alice'))],
            columns: ['age'],
            aggregate: 'first',
        );

        $this->lookupResolver->resolve($source, $this->context());

        $this->assertSame('first', $this->inspector->annotations['aggregate']);
    }

    #[Test]
    public function it_annotates_columns_when_not_empty(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('name', new StaticSource('Alice'))],
            columns: ['age', 'city'],
        );

        $this->lookupResolver->resolve($source, $this->context());

        $this->assertSame(['age', 'city'], $this->inspector->annotations['columns']);
    }

    #[Test]
    public function it_does_not_annotate_columns_when_empty(): void
    {
        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('name', new StaticSource('Alice'))],
            columns: [],
        );

        $this->lookupResolver->resolve($source, $this->context());

        $this->assertArrayNotHasKey('columns', $this->inspector->annotations);
    }

    #[Test]
    public function it_works_without_inspector(): void
    {
        $overloader = new OverloaderManager([new DefaultOverloader()]);

        $delegating = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
        ]);

        $adapter = new LocalFilesystemAdapter(__DIR__.'/../Fixtures');
        $filesystem = new Filesystem($adapter);

        $resolver = new LookupResolver(
            $filesystem,
            $delegating,
            $overloader,
        );

        $source = new LookupSource(
            path: 'users.csv',
            filters: [new ValueFilter('name', new StaticSource('Alice'))],
            columns: ['age'],
        );

        $result = $resolver->resolve($source, new Context());

        $this->assertTrue($result->isOk());
        $this->assertSame('30', $result->unwrap()->unwrap());
    }
}
