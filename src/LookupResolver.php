<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup;

use League\Csv\Reader;
use League\Flysystem\FilesystemOperator;
use RuntimeException;
use Superscript\Axiom\Lookup\Support\Aggregates\Aggregate;
use Superscript\Axiom\Lookup\Support\Aggregates\All;
use Superscript\Axiom\Lookup\Support\Aggregates\Avg;
use Superscript\Axiom\Lookup\Support\Aggregates\Count;
use Superscript\Axiom\Lookup\Support\Aggregates\First;
use Superscript\Axiom\Lookup\Support\Aggregates\Last;
use Superscript\Axiom\Lookup\Support\Aggregates\Max;
use Superscript\Axiom\Lookup\Support\Aggregates\Min;
use Superscript\Axiom\Lookup\Support\Aggregates\Sum;
use Superscript\Axiom\Lookup\Support\Filters\Filter;
use Superscript\Axiom\Lookup\Support\Filters\ResolvedFilter;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\ResolutionInspector;
use Superscript\Axiom\Resolvers\Resolver;
use Superscript\Axiom\Source;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Result;
use Throwable;

use function Psl\Vec\map;
use function Superscript\Monads\Option\None;
use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

/**
 * @implements Resolver<LookupSource>
 */
final readonly class LookupResolver implements Resolver
{
    public function __construct(
        private FilesystemOperator $filesystem,
        private Resolver $resolver,
        private OperatorOverloader $operatorOverloader,
        private ?ResolutionInspector $inspector = null,
    ) {}

    /**
     * @param  LookupSource  $source
     * @return Result<Option<mixed>, Throwable>
     */
    public function resolve(Source $source): Result
    {
        $this->inspector?->annotate('label', $source->path);
        $this->inspector?->annotate('aggregate', $source->aggregate);

        if ($source->columns !== []) {
            $this->inspector?->annotate('columns', $source->columns);
        }

        $stream = null;

        try {
            // Read the CSV/TSV file from Flysystem as a stream
            $stream = $this->filesystem->readStream($source->path);
            
            if ($stream === false) {
                throw new RuntimeException("Could not open file: {$source->path}");
            }

            // Create CSV reader from stream
            $reader = Reader::from($stream);
            $reader->setDelimiter($source->delimiter);

            if ($source->hasHeader) {
                $reader->setHeaderOffset(0);
            }

            // Stream through records with memory-efficient processing
            $records = $source->hasHeader ? $reader->getRecords() : $reader->getRecords([]);

            // Resolve all filter values once before the row loop
            return Result::collect($this->resolveFilters($source->filters))
                ->andThen(function (array $resolvedFilters) use ($records, $source) {
                    $aggregateState = $this->createAggregateState($source->aggregate);

                    foreach ($records as $record) {
                        /** @var array<string, mixed> $record */
                        $csvRecord = CsvRecord::from($record);
                        $filterResult = $this->matchesAllFilters($csvRecord, $resolvedFilters);

                        if ($filterResult->isErr()) {
                            return $filterResult;
                        }

                        if ($filterResult->mapOr(false, fn (bool $v) => $v) === false) {
                            continue;
                        }

                        $aggregateState = $aggregateState->process($csvRecord, $source->aggregateColumn);

                        if ($aggregateState->canEarlyExit()) {
                            break;
                        }
                    }

                    $result = $aggregateState->finalize($source->columns);

                    if ($result === null || (is_array($result) && empty($result))) {
                        return Ok(None());
                    }

                    return Ok(Some($result));
                });
        } catch (Throwable $e) {
            return new Err($e);
        } finally {
            // Ensure stream is always closed
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Create appropriate aggregate state value object
     */
    private function createAggregateState(string $aggregate): Aggregate
    {
        return match ($aggregate) {
            'first' => First::initial(),
            'last' => Last::initial(),
            'count' => Count::initial(),
            'sum' => Sum::initial(),
            'avg' => Avg::initial(),
            'min' => Min::initial(),
            'max' => Max::initial(),
            'all' => All::initial(),
            default => throw new RuntimeException("Unknown aggregate: {$aggregate}"),
        };
    }

    /**
     * @param  array<Filter>  $filters
     * @return list<Result<ResolvedFilter, Throwable>>
     */
    private function resolveFilters(array $filters): array
    {
        return map($filters, fn (Filter $filter): Result => $this->resolver
            ->resolve($filter->value)
            ->map(fn (Option $option) => new ResolvedFilter(
                $filter,
                $option->unwrapOr(null),
            )));
    }

    /**
     * @param  array<ResolvedFilter>  $resolvedFilters
     * @return Result<bool, Throwable>
     */
    private function matchesAllFilters(CsvRecord $record, array $resolvedFilters): Result
    {
        foreach ($resolvedFilters as $resolvedFilter) {
            $result = $resolvedFilter->matches($record, $this->operatorOverloader);

            if ($result->isErr()) {
                return $result;
            }

            if ($result->mapOr(false, fn (bool $v) => $v) === false) {
                return Ok(false);
            }
        }

        return Ok(true);
    }
}
