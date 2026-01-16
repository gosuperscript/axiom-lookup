<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup;

use League\Csv\Reader;
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
use Superscript\Axiom\Source;
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Lookup\Support\Filters\RangeFilter;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Superscript\Monads\Result\Err;
use Superscript\Axiom\Resolvers\Resolver;
use Throwable;

use function Superscript\Monads\Option\None;
use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

/**
 * @implements Resolver<LookupSource>
 */
final readonly class LookupResolver implements Resolver
{
    public function __construct(
        private Resolver $resolver,
    ) {}

    /**
     * @return Result<Option<mixed>, Throwable>
     */
    public function resolve(Source $source): Result
    {
        try {
            // Read and parse the CSV/TSV file
            $reader = Reader::from($source->filePath);
            $reader->setDelimiter($source->delimiter);

            if ($source->hasHeader) {
                $reader->setHeaderOffset(0);
            }

            // Stream through records with memory-efficient processing
            $records = $source->hasHeader ? $reader->getRecords() : $reader->getRecords([]);

            // Initialize aggregate-specific state using value objects
            $aggregateState = $this->createAggregateState($source->aggregate);

            foreach ($records as $record) {
                /** @var array<string, mixed> $record */
                $csvRecord = CsvRecord::from($record);
                $filterResult = $this->matchesAllFilters($csvRecord, $source->filters);

                if ($filterResult->isErr()) {
                    return $filterResult;
                }

                if ($filterResult->unwrap() === false) {
                    continue;
                }

                // Process record immediately with immutable value object
                $aggregateState = $aggregateState->process($csvRecord, $source->aggregateColumn);

                // Early exit optimization for 'first' aggregate
                if ($aggregateState->canEarlyExit()) {
                    break;
                }
            }

            // Finalize and extract result from aggregate state
            $result = $aggregateState->finalize($source->columns);

            if ($result === null || (is_array($result) && empty($result))) {
                return Ok(None());
            }

            return Ok(Some($result));
        } catch (Throwable $e) {
            return new Err($e);
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
     * @param array<Filter> $filters
     */
    private function matchesAllFilters(CsvRecord $record, array $filters): Result
    {
        foreach ($filters as $filter) {
            $resolveResult = $this->resolver->resolve($filter->value);

            if ($resolveResult->isErr() || !$filter->matches($record, $resolveResult->unwrap()->unwrapOr(null))) {
                return $resolveResult->isErr() ? $resolveResult : Ok(false);
            }
        }

        return Ok(true);
    }
}
