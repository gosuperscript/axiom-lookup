<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup;

use League\Csv\Reader;
use League\Flysystem\FilesystemOperator;
use RuntimeException;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Extension;
use Superscript\Axiom\Lookup\Support\Aggregates\AggregateFactory;
use Superscript\Axiom\Lookup\Support\Filters\CompiledFilter;
use Superscript\Axiom\Lookup\Support\Filters\Filter;
use Superscript\Axiom\Lookup\Support\Filters\RangeFilter;
use Superscript\Axiom\Lookup\Support\Filters\ResolvedFilter;
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Runtime;
use Superscript\Axiom\Source;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
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
 * `Option<Number>`; `all` is a total collection and declares `List<Unknown>`;
 * every other aggregate hands back raw CSV data whose type is genuinely
 * unknowable at compile time, so it declares `Option<Unknown>`. A downstream
 * consumer bridges `Unknown` with an explicit `Coerce`/`Ascription`.
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

            $compiled = $this->compileFilter($source, $filter, $node->unwrap(), $compilation);

            if ($compiled->isErr()) {
                return $compiled;
            }

            $compiledFilters[] = $compiled->unwrap();
        }

        return Ok(new CompiledNode(
            $this->resultType($source),
            fn(Runtime $runtime): Result => $this->evaluate($source, $runtime, $compiledFilters),
        ));
    }

    /**
     * The declared payload type. Numeric aggregates are statically known;
     * all is a total collection; the remaining aggregates yield raw cells
     * that cannot be typed.
     */
    private function resultType(LookupSource $source): Type
    {
        return match ($source->aggregate) {
            'count', 'sum', 'avg' => new OptionType(new NumberType()),
            'all' => new ListType(new UnknownType()),
            default => new OptionType(new UnknownType()),
        };
    }

    /** @return Result<CompiledFilter, TypeMismatch> */
    private function compileFilter(
        LookupSource $source,
        Filter $filter,
        CompiledNode $value,
        SourceCompilation $compilation,
    ): Result {
        if ($filter instanceof ValueFilter) {
            return $this->compileValueFilter($source, $filter, $value, $compilation);
        }

        if ($filter instanceof RangeFilter) {
            return $this->compileRangeFilter($source, $filter, $value, $compilation);
        }

        return new Err(new TypeMismatch(sprintf(
            'Filter [%s] has no compiler in LookupExtension.',
            $filter::class,
        )));
    }

    /** @return Result<CompiledFilter, TypeMismatch> */
    private function compileValueFilter(
        LookupSource $source,
        ValueFilter $filter,
        CompiledNode $value,
        SourceCompilation $compilation,
    ): Result {
        $cellType = $this->columnType($source, $filter->column);

        return $this->booleanInfix($compilation, $cellType, $filter->operator, $value->returns)
            ->map(fn(ResolvedOperation $operation) => new CompiledFilter(
                $value,
                fn(CsvRecord $record, mixed $resolved): Result => $this->matchValue(
                    $record,
                    $filter->column,
                    $cellType,
                    $resolved,
                    $operation,
                ),
            ));
    }

    /** @return Result<CompiledFilter, TypeMismatch> */
    private function compileRangeFilter(
        LookupSource $source,
        RangeFilter $filter,
        CompiledNode $value,
        SourceCompilation $compilation,
    ): Result {
        $minimumType = $this->columnType($source, $filter->minColumn);
        $maximumType = $this->columnType($source, $filter->maxColumn);

        return $this->booleanInfix($compilation, $value->returns, '>=', $minimumType)
            ->andThen(fn(ResolvedOperation $minimum) => $this
                ->booleanInfix($compilation, $value->returns, '<', $maximumType)
                ->map(fn(ResolvedOperation $maximum) => new CompiledFilter(
                    $value,
                    fn(CsvRecord $record, mixed $resolved): Result => $this->matchRange(
                        $record,
                        $filter,
                        $minimumType,
                        $maximumType,
                        $resolved,
                        $minimum,
                        $maximum,
                    ),
                )));
    }

    /** @return Result<ResolvedOperation, TypeMismatch> */
    private function booleanInfix(
        SourceCompilation $compilation,
        Type $left,
        string $operator,
        Type $right,
    ): Result {
        return $compilation->infix($left, $operator, $right)
            ->andThen(fn(ResolvedOperation $operation) => TypeRelations::isTypeAssignableTo(
                $operation->returns,
                new BooleanType(),
            )
                ->mapErr(fn(TypeMismatch $cause) => new TypeMismatch(sprintf(
                    'Lookup filter operator [%s] must return Boolean.',
                    $operator,
                ), [$cause]))
                ->map(fn() => $operation));
    }

    private function columnType(LookupSource $source, string|int $column): Type
    {
        return $source->schema[$column] ?? new StringType();
    }

    /** @return Result<bool, Throwable> */
    private function matchValue(
        CsvRecord $record,
        string|int $column,
        Type $cellType,
        mixed $value,
        ResolvedOperation $operation,
    ): Result {
        return $this->readCellAs($record, $column, $cellType)
            ->andThen(fn(Option $cell): Result => $cell->isNone()
                ? Ok(false)
                : $this->evaluateBoolean($operation, $cell->unwrap(), $value));
    }

    /** @return Result<bool, Throwable> */
    private function matchRange(
        CsvRecord $record,
        RangeFilter $filter,
        Type $minimumType,
        Type $maximumType,
        mixed $value,
        ResolvedOperation $minimumOperation,
        ResolvedOperation $maximumOperation,
    ): Result {
        $minimum = $this->readCellAs($record, $filter->minColumn, $minimumType);

        if ($minimum->isErr()) {
            return $minimum;
        }

        $maximum = $this->readCellAs($record, $filter->maxColumn, $maximumType);

        if ($maximum->isErr()) {
            return $maximum;
        }

        if ($minimum->unwrap()->isNone() || $maximum->unwrap()->isNone()) {
            return Ok(false);
        }

        $aboveMinimum = $this->evaluateBoolean($minimumOperation, $value, $minimum->unwrap()->unwrap());

        if ($aboveMinimum->isErr() || $aboveMinimum->unwrap() === false) {
            return $aboveMinimum;
        }

        return $this->evaluateBoolean($maximumOperation, $value, $maximum->unwrap()->unwrap());
    }

    /** @return Result<Option<mixed>, Throwable> */
    private function readCellAs(CsvRecord $record, string|int $column, Type $type): Result
    {
        if (! $record->has($column)) {
            return Ok(None());
        }

        $value = $record->get($column);

        // League CSV already supplies strings. Keep the default CSV domain
        // lossless: StringType::coerce() deliberately reads '' and 'null' as
        // absence at lenient input boundaries, but they are valid raw cells.
        return $type::class === StringType::class && is_string($value)
            ? $type->assert($value)
            : $type->coerce($value);
    }

    /** @return Result<bool, Throwable> */
    private function evaluateBoolean(ResolvedOperation $operation, mixed $left, mixed $right): Result
    {
        /** @var Result<bool, Throwable> $result */
        $result = $operation->evaluate($left, $right);

        return $result;
    }

    /**
     * Stream the file once, matching each row against the filters and folding
     * it into the aggregate — O(1) memory, no buffering of rows.
     *
     * @param list<CompiledFilter> $compiledFilters
     * @return Result<Option<mixed>, Throwable>
     */
    private function evaluate(LookupSource $source, Runtime $runtime, array $compiledFilters): Result
    {
        $runtime->annotate('aggregate', $source->aggregate);

        if ($source->columns !== []) {
            $runtime->annotate('columns', $source->columns);
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

                    if ($source->aggregate === 'all') {
                        return Ok(Some($result));
                    }

                    if ($result === null) {
                        return Ok(None());
                    }

                    return Ok(Some($result));
                });

            $runtime->annotate('label', $source->path);

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
     * @param list<CompiledFilter> $compiledFilters
     * @return list<Result<ResolvedFilter, Throwable>>
     */
    private function resolveFilters(array $compiledFilters, Runtime $runtime): array
    {
        return map($compiledFilters, fn(CompiledFilter $filter): Result => $filter->resolve($runtime));
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
