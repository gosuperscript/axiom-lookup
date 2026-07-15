<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup;

use League\Csv\Reader;
use League\Flysystem\FilesystemOperator;
use RuntimeException;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Lookup\Support\Aggregates\AggregateFactory;
use Superscript\Axiom\Lookup\Support\Filters\Filter;
use Superscript\Axiom\Lookup\Support\Filters\ResolvedFilter;
use Superscript\Axiom\Runtime;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeEnvironment;
use Superscript\Axiom\Types\TypeInference;
use Superscript\Axiom\TypedSource;
use Superscript\Axiom\Types\UnknownType;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Result;
use Throwable;

use function Psl\Vec\map;
use function Superscript\Monads\Option\None;
use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

/**
 * A CSV/TSV lookup as a first-class compiler citizen: one statement carries
 * both the type the lookup produces and the streaming evaluation that
 * produces it. The old runtime split this across a dumb config `Source` and
 * a container-wired `Resolver`; the compile-then-run model has no resolver
 * to dispatch to, so the source compiles itself.
 *
 * Its type claim is deliberately honest about what a CSV can promise:
 *
 * - `count`/`sum`/`avg` are numeric by construction, so they declare
 *   `Option<Number>` — "no matching row" is a legal `None`.
 * - Every other aggregate hands back a raw CSV cell (or a row of them),
 *   whose type is genuinely unknowable at compile time, so it declares
 *   `Option<Unknown>`. A downstream consumer bridges that `Unknown` with an
 *   explicit `Coerce`/`Ascription` — the honest "raw lookup cell" posture.
 *
 * The collaborator the old resolver received from the container — the
 * {@see FilesystemOperator} — now arrives through the constructor, the way
 * every host source in the extension guide carries its own dependencies.
 */
final readonly class LookupSource implements TypedSource
{
    /**
     * @param array<Filter> $filters
     * @param array<string|int> $columns
     */
    public function __construct(
        public string $path,
        private FilesystemOperator $filesystem,
        public array $filters = [],
        public array $columns = [],
        public string $aggregate = 'first',
        public string|int|null $aggregateColumn = null,
        public string $delimiter = ',',
        public bool $hasHeader = true,
    ) {}

    public function compile(TypeEnvironment $environment, TypeInference $compiler): Result
    {
        // A filter's comparison value is itself a Source (a StaticSource, or
        // another LookupSource for a nested/dynamic lookup). Compile each
        // once here; the paired node is evaluated once per invocation before
        // the row loop, mirroring the old "resolve filters once, then stream".
        $compiledFilters = [];

        foreach ($this->filters as $filter) {
            $node = $compiler->compile($filter->value, $environment);

            if ($node->isErr()) {
                return $node;
            }

            $compiledFilters[] = [$filter, $node->unwrap()];
        }

        return Ok(new CompiledNode(
            $this->resultType(),
            fn(Runtime $runtime): Result => $this->evaluate($runtime, $compiledFilters),
        ));
    }

    /**
     * The declared payload type. Numeric aggregates are statically known;
     * every other aggregate yields raw cells that cannot be typed.
     */
    private function resultType(): Type
    {
        return in_array($this->aggregate, ['count', 'sum', 'avg'], true)
            ? new OptionType(new NumberType())
            : new OptionType(new UnknownType());
    }

    /**
     * Stream the file once, matching each row against the filters and
     * folding it into the aggregate — O(1) memory, no buffering of rows.
     *
     * @param list<array{Filter, CompiledNode}> $compiledFilters
     * @return Result<Option<mixed>, Throwable>
     */
    private function evaluate(Runtime $runtime, array $compiledFilters): Result
    {
        $runtime->inspector?->annotate('aggregate', $this->aggregate);

        if ($this->columns !== []) {
            $runtime->inspector?->annotate('columns', $this->columns);
        }

        $stream = null;

        try {
            // Read the CSV/TSV file from Flysystem as a stream
            $stream = $this->filesystem->readStream($this->path);

            if ($stream === false) {
                throw new RuntimeException("Could not open file: {$this->path}");
            }

            // Create CSV reader from the open stream resource
            $reader = Reader::from($stream);
            $reader->setDelimiter($this->delimiter);

            if ($this->hasHeader) {
                $reader->setHeaderOffset(0);
            }

            // Stream through records with memory-efficient processing
            $records = $this->hasHeader ? $reader->getRecords() : $reader->getRecords([]);

            // Evaluate every filter value once before the row loop
            $result = Result::collect($this->resolveFilters($compiledFilters, $runtime))
                ->andThen(function (array $resolvedFilters) use ($records): Result {
                    $aggregateState = AggregateFactory::for($this->aggregate);

                    foreach ($records as $record) {
                        /** @var array<string, mixed> $record */
                        $csvRecord = CsvRecord::from($record);
                        $filterResult = $this->matchesAllFilters($csvRecord, $resolvedFilters);

                        if ($filterResult->isErr()) {
                            return $filterResult;
                        }

                        if ($filterResult->mapOr(false, fn(bool $v) => $v) === false) {
                            continue;
                        }

                        $aggregateState = $aggregateState->process($csvRecord, $this->aggregateColumn);

                        if ($aggregateState->canEarlyExit()) {
                            break;
                        }
                    }

                    $result = $aggregateState->finalize($this->columns);

                    if ($result === null || (is_array($result) && $result === [])) {
                        return Ok(None());
                    }

                    return Ok(Some($result));
                });

            $runtime->inspector?->annotate('label', $this->path);

            return $result;
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
     * @param list<array{Filter, CompiledNode}> $compiledFilters
     * @return list<Result<ResolvedFilter, Throwable>>
     */
    private function resolveFilters(array $compiledFilters, Runtime $runtime): array
    {
        return map($compiledFilters, fn(array $pair): Result => ($pair[1]->evaluate)($runtime)
            ->map(fn(Option $option) => new ResolvedFilter(
                $pair[0],
                $option->unwrapOr(null),
            )));
    }

    /**
     * @param array<ResolvedFilter> $resolvedFilters
     * @return Result<bool, Throwable>
     */
    private function matchesAllFilters(CsvRecord $record, array $resolvedFilters): Result
    {
        foreach ($resolvedFilters as $resolvedFilter) {
            $result = $resolvedFilter->matches($record);

            if ($result->isErr()) {
                return $result;
            }

            if ($result->mapOr(false, fn(bool $v) => $v) === false) {
                return Ok(false);
            }
        }

        return Ok(true);
    }
}
