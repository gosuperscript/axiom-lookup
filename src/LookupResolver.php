<?php

declare(strict_types=1);

namespace Superscript\Schema\Lookup\Resolvers;

use League\Csv\Reader;
use RuntimeException;
use Superscript\Schema\Lookup\Resolvers\LookupResolver\AggregateState;
use Superscript\Schema\Lookup\Resolvers\LookupResolver\AvgAggregateState;
use Superscript\Schema\Lookup\Resolvers\LookupResolver\CountAggregateState;
use Superscript\Schema\Lookup\Resolvers\LookupResolver\CsvRecord;
use Superscript\Schema\Lookup\Resolvers\LookupResolver\FirstAggregateState;
use Superscript\Schema\Lookup\Resolvers\LookupResolver\LastAggregateState;
use Superscript\Schema\Lookup\Resolvers\LookupResolver\MaxAggregateState;
use Superscript\Schema\Lookup\Resolvers\LookupResolver\MinAggregateState;
use Superscript\Schema\Lookup\Resolvers\LookupResolver\SumAggregateState;
use Superscript\Schema\Source;
use Superscript\Schema\Lookup\Sources\ExactFilter;
use Superscript\Schema\Lookup\Sources\LookupSource;
use Superscript\Schema\Lookup\Sources\RangeFilter;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Superscript\Monads\Result\Err;
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

            // Resolve all filters
            $resolvedExactFilters = [];
            $resolvedRangeFilters = [];
            
            foreach ($source->filters as $filter) {
                $result = $this->resolver->resolve($filter->value);
                
                if ($result->isErr()) {
                    return $result;
                }
                
                $option = $result->unwrap();
                if ($option->isNone()) {
                    return Ok(None());
                }
                
                $resolvedValue = $option->unwrap();
                
                if ($filter instanceof ExactFilter) {
                    $resolvedExactFilters[$filter->column] = $resolvedValue;
                } elseif ($filter instanceof RangeFilter) {
                    $resolvedRangeFilters[] = [
                        'value' => $resolvedValue,
                        'minColumn' => $filter->minColumn,
                        'maxColumn' => $filter->maxColumn,
                    ];
                }
            }

            // Stream through records with memory-efficient processing
            $records = $source->hasHeader ? $reader->getRecords() : $reader->getRecords([]);
            
            // Initialize aggregate-specific state using value objects
            $aggregateState = $this->createAggregateState($source->aggregate);
            
            foreach ($records as $record) {
                /** @var array<string, mixed> $record */
                $csvRecord = CsvRecord::from($record);
                
                if ($this->matchesExactFilters($csvRecord, $resolvedExactFilters) && $this->matchesRangeFilters($csvRecord, $resolvedRangeFilters)) {
                    // Process record immediately with immutable value object
                    $aggregateState = $aggregateState->process($csvRecord, $source->aggregateColumn);
                    
                    // Early exit optimization for 'first' aggregate
                    if ($aggregateState->canEarlyExit()) {
                        break;
                    }
                }
            }

            // Finalize and extract result from aggregate state
            $result = $aggregateState->finalize($source->columns);
            
            if ($result === null) {
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
    private function createAggregateState(string $aggregate): AggregateState
    {
        return match ($aggregate) {
            'first' => FirstAggregateState::initial(),
            'last' => LastAggregateState::initial(),
            'count' => CountAggregateState::initial(),
            'sum' => SumAggregateState::initial(),
            'avg' => AvgAggregateState::initial(),
            'min' => MinAggregateState::initial(),
            'max' => MaxAggregateState::initial(),
            default => throw new RuntimeException("Unknown aggregate: {$aggregate}"),
        };
    }

    /**
     * @param array<string|int, mixed> $filters
     */
    private function matchesExactFilters(CsvRecord $record, array $filters): bool
    {
        foreach ($filters as $column => $value) {
            $recordValue = $record->getString($column);
            $compareValue = is_scalar($value) ? (string) $value : null;
            
            if ($recordValue === null || $compareValue === null || $recordValue !== $compareValue) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * @param array<array{value: mixed, minColumn: string|int, maxColumn: string|int}> $rangeFilters
     */
    private function matchesRangeFilters(CsvRecord $record, array $rangeFilters): bool
    {
        foreach ($rangeFilters as $rangeConfig) {
            $value = $rangeConfig['value'];
            $minColumn = $rangeConfig['minColumn'];
            $maxColumn = $rangeConfig['maxColumn'];
            
            if (!$record->has($minColumn) || !$record->has($maxColumn)) {
                return false;
            }
            
            $minValue = $record->get($minColumn);
            $maxValue = $record->get($maxColumn);
            
            // Check if value falls within the range [min, max)
            // Using min <= value < max for banding scenarios
            // This prevents overlap at boundaries (e.g., 100k matches 100k-200k, not 0-100k)
            if (is_numeric($value) && is_numeric($minValue) && is_numeric($maxValue)) {
                if ($value < $minValue || $value >= $maxValue) {
                    return false;
                }
            } else {
                // String comparison fallback
                if ($value < $minValue || $value >= $maxValue) {
                    return false;
                }
            }
        }
        
        return true;
    }
}
