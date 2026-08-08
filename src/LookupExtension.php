<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup;

use League\Csv\Reader;
use League\Flysystem\FilesystemOperator;
use RuntimeException;
use Superscript\Axiom\BoundOperation;
use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\Extension;
use Superscript\Axiom\Lookup\Support\Aggregates\AggregateFactory;
use Superscript\Axiom\Lookup\Support\Filters\CompiledFilter;
use Superscript\Axiom\Lookup\Support\Filters\Filter;
use Superscript\Axiom\Lookup\Support\Filters\RangeFilter;
use Superscript\Axiom\Lookup\Support\Filters\ResolvedFilter;
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Source;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\SourceEvaluation;
use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Axiom\Types\UnknownType;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

use function Psl\Iter\first;
use function Psl\Type\instance_of;
use function Psl\Vec\map;
use function Superscript\Monads\Option\None;
use function Superscript\Monads\Result\attempt;
use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * The lookup package's contribution to a {@see \Superscript\Axiom\Dialect}:
 * the source compiler that turns a data-only {@see LookupSource} into a
 * streaming {@see CompiledSource}. The {@see FilesystemOperator} the read needs
 * is injected here — the live collaborator the old container wired into the
 * resolver — and captured in the compiled program, so the persisted
 * `LookupSource` tree carries no filesystem of its own.
 *
 * ```php
 * $dialect = Dialect::core()->with(new LookupExtension($filesystem));
 * $program = (new Expression($lookupSource, dialect: $dialect))->compile()->unwrap();
 * ```
 *
 * The compiled source's type is only ever as strong as the file's own
 * promise. `count`/`sum`/`avg` are numeric by construction, so they declare
 * `Option<Number>` whatever the file says. Every other aggregate hands back
 * cells, and a cell is typed only where {@see LookupSource::$schema} declares
 * its column: projecting the single column `Product Group`, declared
 * `String`, makes `all` return `List<String>` and `first` return
 * `Option<String>`. An undeclared column — or a projection of several columns
 * or of the whole row, which yields a column-keyed array rather than a cell —
 * stays `Unknown`, and a downstream consumer bridges it with an explicit
 * `Coerce`/`Ascription`.
 *
 * A declaration is a promise the reads keep: every cell of a declared column
 * is admitted through that type before it leaves, so `List<String>` really
 * holds strings and a `Number` column really yields numbers. A cell that
 * cannot be admitted fails the whole lookup with an `Err` naming the file and
 * the column, rather than quietly handing a liar's value to a checker that
 * took the declaration at face value.
 */
final class LookupExtension extends Extension
{
    public function __construct(private readonly FilesystemOperator $filesystem) {}

    public function sourceCompilers(): array
    {
        return [LookupSource::class => $this->compile(...)];
    }

    private function compile(Source $source, SourceCompilation $compilation): CompiledSource
    {
        // The registry keys this compiler on LookupSource::class, so the
        // contract's Source is always ours; narrow it for the type checker.
        $source = instance_of(LookupSource::class)->assert($source);

        // A filter's comparison value is itself a Source (a StaticSource, or
        // another LookupSource for a nested/dynamic lookup). Compile each once
        // here; the paired source is evaluated once per invocation before the
        // row loop, mirroring the old "resolve filters once, then stream".
        $compiledFilters = [];

        foreach ($source->filters as $filter) {
            $value = $compilation->child($filter->value);
            $compiledFilters[] = $this->compileFilter($source, $filter, $value, $compilation);
        }

        return $compilation->custom(
            $this->resultType($source),
            fn(SourceEvaluation $evaluation): Result => $this->evaluate($source, $evaluation, $compiledFilters),
        );
    }

    /**
     * The declared payload type. Numeric aggregates are statically known;
     * all is a total collection of projected cells; the remaining aggregates
     * yield one projected cell.
     */
    private function resultType(LookupSource $source): Type
    {
        if ($this->hasNumericResult($source)) {
            return new OptionType(new NumberType());
        }

        return match ($source->aggregate) {
            'all' => new ListType($this->projectedType($source)),
            default => new OptionType($this->projectedType($source)),
        };
    }

    /** Numeric aggregates construct a number rather than projecting a cell. */
    private function hasNumericResult(LookupSource $source): bool
    {
        return in_array($source->aggregate, ['count', 'sum', 'avg'], strict: true);
    }

    /**
     * The type of one projected cell: the declared type of the projected
     * column, or Unknown when the column is undeclared — and Unknown too when
     * there is no single projected column to speak of.
     */
    private function projectedType(LookupSource $source): Type
    {
        $column = $this->projectedColumn($source);

        if ($column === null) {
            return new UnknownType();
        }

        return $source->schema[$column] ?? new UnknownType();
    }

    /**
     * The one column a projection reads, if it reads exactly one. Projecting
     * nothing hands back the whole row and projecting several hands back a
     * column-keyed array (see {@see CsvRecord::extract()}); neither is a cell,
     * so neither can take a column's declared type.
     */
    private function projectedColumn(LookupSource $source): string|int|null
    {
        return count($source->columns) === 1 ? first($source->columns) : null;
    }

    private function compileFilter(
        LookupSource $source,
        Filter $filter,
        CompiledSource $value,
        SourceCompilation $compilation,
    ): CompiledFilter {
        if ($filter instanceof ValueFilter) {
            return $this->compileValueFilter($source, $filter, $value, $compilation);
        }

        if ($filter instanceof RangeFilter) {
            return $this->compileRangeFilter($source, $filter, $value, $compilation);
        }

        $compilation->reject(new TypeMismatch(sprintf(
            'Filter [%s] has no compiler in LookupExtension.',
            $filter::class,
        )));
    }

    private function compileValueFilter(
        LookupSource $source,
        ValueFilter $filter,
        CompiledSource $value,
        SourceCompilation $compilation,
    ): CompiledFilter {
        $cellType = $this->columnType($source, $filter->column);
        $operation = $this->booleanInfix($compilation, $cellType, $filter->operator, $value->returns);

        return new CompiledFilter(
            $value,
            fn(CsvRecord $record, mixed $resolved): Result => $this->matchValue(
                $record,
                $filter->column,
                $cellType,
                $resolved,
                $operation,
            ),
        );
    }

    private function compileRangeFilter(
        LookupSource $source,
        RangeFilter $filter,
        CompiledSource $value,
        SourceCompilation $compilation,
    ): CompiledFilter {
        $minimumType = $this->columnType($source, $filter->minColumn);
        $maximumType = $this->columnType($source, $filter->maxColumn);
        $minimum = $this->booleanInfix($compilation, $value->returns, '>=', $minimumType);
        $maximum = $this->booleanInfix($compilation, $value->returns, '<', $maximumType);

        return new CompiledFilter(
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
        );
    }

    private function booleanInfix(
        SourceCompilation $compilation,
        Type $left,
        string $operator,
        Type $right,
    ): BoundOperation {
        $operation = $compilation->infix($left, $operator, $right);
        $assignable = TypeRelations::isTypeAssignableTo($operation->returns, new BooleanType());

        if ($assignable->isErr()) {
            $compilation->reject(new TypeMismatch(sprintf(
                'Lookup filter operator [%s] must return Boolean.',
                $operator,
            ), [$assignable->unwrapErr()]));
        }

        return $operation;
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
        BoundOperation $operation,
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
        BoundOperation $minimumOperation,
        BoundOperation $maximumOperation,
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

        return $this->castCell($record->get($column), $type);
    }

    /**
     * Admit one raw cell into a type.
     *
     * League CSV already supplies strings. Keep the default CSV domain
     * lossless: StringType::coerce() deliberately reads '' and 'null' as
     * absence at lenient input boundaries, but they are valid raw cells.
     *
     * @return Result<Option<mixed>, Throwable>
     */
    private function castCell(mixed $value, Type $type): Result
    {
        return $type::class === StringType::class && is_string($value)
            ? $type->assert($value)
            : $type->coerce($value);
    }

    /**
     * Make the projected value inhabit the type {@see resultType()} declared
     * for it. Only a single projected column of a declared type is admitted;
     * everything else is Unknown and passes through as the raw CSV data it
     * has always been.
     *
     * `all` declares `List<T>`, whose items are present values, so a row whose
     * cell reads as absent — an empty cell in a `Number` column — breaks the
     * declaration and fails the lookup. The single-value aggregates declare
     * `Option<T>`, where absence is a legal value, so an unmatched lookup or
     * an absent cell is null as it always was.
     *
     * @return Result<mixed, Throwable>
     */
    private function admitProjection(LookupSource $source, mixed $projected): Result
    {
        if ($this->hasNumericResult($source)) {
            return Ok($projected);
        }

        $column = $this->projectedColumn($source);

        if ($column === null || !isset($source->schema[$column])) {
            return Ok($projected);
        }

        $type = $source->schema[$column];

        if ($source->aggregate === 'all') {
            /** @var list<mixed> $projected */
            return Result::collect(map(
                $projected,
                fn(mixed $cell): Result => $this->admitCell($source, $column, $type, $cell)->andThen(
                    fn(Option $value): Result => $value->mapOr(
                        default: Err(new RuntimeException(sprintf(
                            'Column [%s] of [%s] is declared %s, but a matching row has no value for it.',
                            $column,
                            $source->path,
                            TypeDescriber::describe($type),
                        ))),
                        f: fn(mixed $cell): Result => Ok($cell),
                    ),
                ),
            ));
        }

        return $this->admitCell($source, $column, $type, $projected)
            ->map(fn(Option $value): mixed => $value->unwrapOr(null));
    }

    /** @return Result<Option<mixed>, Throwable> */
    private function admitCell(LookupSource $source, string|int $column, Type $type, mixed $cell): Result
    {
        if ($cell === null) {
            return Ok(None());
        }

        return $this->castCell($cell, $type)->mapErr(fn(Throwable $error) => new RuntimeException(sprintf(
            'Column [%s] of [%s] is declared %s, but a matching row holds [%s].',
            $column,
            $source->path,
            TypeDescriber::describe($type),
            TransformValueException::format($cell),
        ), previous: $error));
    }

    /** @return Result<bool, Throwable> */
    private function evaluateBoolean(BoundOperation $operation, mixed $left, mixed $right): Result
    {
        /** @var bool $result */
        $result = $operation($left, $right);

        return Ok($result);
    }

    /**
     * Stream the file once, matching each row against the filters and folding
     * it into the aggregate — O(1) memory, no buffering of rows.
     *
     * @param list<CompiledFilter> $compiledFilters
     * @return Result<mixed, Throwable>
     */
    private function evaluate(LookupSource $source, SourceEvaluation $evaluation, array $compiledFilters): Result
    {
        $evaluation->annotate('aggregate', $source->aggregate);

        if ($source->columns !== []) {
            $evaluation->annotate('columns', $source->columns);
        }

        // Child failures must reach CompiledSource's private failure channel,
        // so resolve filter values before the package-owned I/O boundary.
        $resolvedFilters = $this->resolveFilters($compiledFilters, $evaluation);
        $stream = null;

        try {
            $records = attempt(function () use ($source, &$stream): iterable {
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
                return $source->hasHeader ? $reader->getRecords() : $reader->getRecords([]);
            });

            if ($records->isErr()) {
                return $records;
            }

            $aggregateState = attempt(fn() => AggregateFactory::for($source->aggregate));

            if ($aggregateState->isErr()) {
                return $aggregateState;
            }

            $aggregateState = $aggregateState->unwrap();

            foreach ($records->unwrap() as $record) {
                /** @var array<string, mixed> $record */
                $csvRecord = CsvRecord::from($record);
                $filterResult = $this->matchesAllFilters($csvRecord, $resolvedFilters);

                if ($filterResult->isErr()) {
                    return $filterResult;
                }

                if ($filterResult->mapOr(false, fn(bool $v) => $v) === false) {
                    continue;
                }

                $processed = attempt(fn() => $aggregateState->process($csvRecord, $source->aggregateColumn));

                if ($processed->isErr()) {
                    return $processed;
                }

                $aggregateState = $processed->unwrap();

                if ($aggregateState->canEarlyExit()) {
                    break;
                }
            }

            $result = $this->admitProjection($source, $aggregateState->finalize($source->columns));

            $evaluation->annotate('label', $source->path);

            return $result;
        } finally {
            // Ensure stream is always closed
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @param list<CompiledFilter> $compiledFilters
     * @return list<ResolvedFilter>
     */
    private function resolveFilters(array $compiledFilters, SourceEvaluation $evaluation): array
    {
        return map($compiledFilters, fn(CompiledFilter $filter) => $filter->resolve($evaluation));
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
