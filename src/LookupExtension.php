<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup;

use League\Csv\Reader;
use League\Flysystem\FilesystemOperator;
use RuntimeException;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Extension;
use Superscript\Axiom\Lookup\Support\Aggregates\AggregateFactory;
use Superscript\Axiom\Lookup\Support\Filters\Filter;
use Superscript\Axiom\Lookup\Support\Filters\ResolvedFilter;
use Superscript\Axiom\Runtime;
use Superscript\Axiom\Source;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\UnknownType;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Result;
use Throwable;

use function Psl\Type\instance_of;
use function Psl\Vec\map;
use function Superscript\Monads\Option\None;
use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

/**
 * The lookup package's contribution to a {@see \Superscript\Axiom\Dialect}:
 * the source compiler that turns a data-only {@see LookupSource} into a
 * streaming {@see CompiledNode}. The {@see FilesystemOperator} the read needs
 * is injected here — the live collaborator the old container wired into the
 * resolver — and captured in the compiled program, so the persisted
 * `LookupSource` tree carries no filesystem of its own.
 *
 * ```php
 * $dialect = Dialect::core()->with(new LookupExtension($filesystem));
 * $program = (new Expression($lookupSource, dialect: $dialect))->compile()->unwrap();
 * ```
 *
 * The compiled node's type is deliberately honest about what a CSV can
 * promise: `count`/`sum`/`avg` are numeric by construction, so they declare
 * `Option<Number>`; every other aggregate hands back a raw CSV cell (or a row
 * of them), whose type is genuinely unknowable at compile time, so it declares
 * `Option<Unknown>`, which a downstream consumer bridges with an explicit
 * `Coerce`/`Ascription`. "No matching row" is a legal `None` either way.
 */
final class LookupExtension extends Extension
{
    public function __construct(private readonly FilesystemOperator $filesystem) {}

    public function sourceCompilers(): array
    {
        return [LookupSource::class => $this->compile(...)];
    }

    /**
     * @return Result<CompiledNode, \Superscript\Axiom\Types\TypeMismatch>
     */
    private function compile(Source $source, SourceCompilation $compilation): Result
    {
        // The registry keys this compiler on LookupSource::class, so the
        // contract's Source is always ours; narrow it for the type checker.
        $source = instance_of(LookupSource::class)->assert($source);

        // A filter's comparison value is itself a Source (a StaticSource, or
        // another LookupSource for a nested/dynamic lookup). Compile each once
        // here; the paired node is evaluated once per invocation before the
        // row loop, mirroring the old "resolve filters once, then stream".
        $compiledFilters = [];

        foreach ($source->filters as $filter) {
            $node = $compilation->compile($filter->value);

            if ($node->isErr()) {
                return $node;
            }

            $compiledFilters[] = [$filter, $node->unwrap()];
        }

        return Ok(new CompiledNode(
            $this->resultType($source),
            fn(Runtime $runtime): Result => $this->evaluate($source, $runtime, $compiledFilters),
        ));
    }

    /**
     * The declared payload type. Numeric aggregates are statically known;
     * every other aggregate yields raw cells that cannot be typed.
     */
    private function resultType(LookupSource $source): Type
    {
        return in_array($source->aggregate, ['count', 'sum', 'avg'], true)
            ? new OptionType(new NumberType())
            : new OptionType(new UnknownType());
    }

    /**
     * Stream the file once, matching each row against the filters and folding
     * it into the aggregate — O(1) memory, no buffering of rows.
     *
     * @param list<array{Filter, CompiledNode}> $compiledFilters
     * @return Result<Option<mixed>, Throwable>
     */
    private function evaluate(LookupSource $source, Runtime $runtime, array $compiledFilters): Result
    {
        $runtime->inspector?->annotate('aggregate', $source->aggregate);

        if ($source->columns !== []) {
            $runtime->inspector?->annotate('columns', $source->columns);
        }

        $stream = null;

        try {
            // Read the CSV/TSV file from Flysystem as a stream
            $stream = $this->filesystem->readStream($source->path);

            if ($stream === false) {
                throw new RuntimeException("Could not open file: {$source->path}");
            }

            // Create CSV reader from the open stream resource
            $reader = Reader::from($stream);
            $reader->setDelimiter($source->delimiter);

            if ($source->hasHeader) {
                $reader->setHeaderOffset(0);
            }

            // Stream through records with memory-efficient processing
            $records = $source->hasHeader ? $reader->getRecords() : $reader->getRecords([]);

            // Evaluate every filter value once before the row loop
            $result = Result::collect($this->resolveFilters($compiledFilters, $runtime))
                ->andThen(function (array $resolvedFilters) use ($source, $records): Result {
                    $aggregateState = AggregateFactory::for($source->aggregate);

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

                        $aggregateState = $aggregateState->process($csvRecord, $source->aggregateColumn);

                        if ($aggregateState->canEarlyExit()) {
                            break;
                        }
                    }

                    $result = $aggregateState->finalize($source->columns);

                    if ($result === null || (is_array($result) && $result === [])) {
                        return Ok(None());
                    }

                    return Ok(Some($result));
                });

            $runtime->inspector?->annotate('label', $source->path);

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
