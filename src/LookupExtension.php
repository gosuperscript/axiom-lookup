<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup;

use League\Csv\Reader;
use League\Flysystem\FilesystemOperator;
use RuntimeException;
use Superscript\Axiom\BoundOperation;
use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Axiom\Extension;
use Superscript\Axiom\Lookup\Support\Filters\CompiledFilter;
use Superscript\Axiom\Lookup\Support\Filters\Filter;
use Superscript\Axiom\Lookup\Support\Filters\RangeFilter;
use Superscript\Axiom\Lookup\Support\Filters\ResolvedFilter;
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Lookup\Support\Results\AllRows;
use Superscript\Axiom\Lookup\Support\Results\CompiledLookupResult;
use Superscript\Axiom\Lookup\Support\Results\CountRows;
use Superscript\Axiom\Lookup\Support\Results\FirstRow;
use Superscript\Axiom\Lookup\Support\Results\LastRow;
use Superscript\Axiom\Lookup\Support\Results\MinimumRow;
use Superscript\Axiom\Lookup\Support\Results\NumericResult;
use Superscript\Axiom\Lookup\Support\Results\ProjectedResult;
use Superscript\Axiom\Lookup\Support\Results\RecordProjection;
use Superscript\Axiom\Lookup\Support\Results\SumColumn;
use Superscript\Axiom\Lookup\Support\Results\ValueProjection;
use Superscript\Axiom\Source;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\SourceEvaluation;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\PresentType;
use Superscript\Axiom\Types\RecordType;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

use function Psl\Type\instance_of;
use function Psl\Vec\map;
use function Superscript\Monads\Result\attempt;
use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/** Compiles and evaluates typed lookups over serializable delimited tables. */
final class LookupExtension extends Extension
{
    public function __construct(private readonly FilesystemOperator $filesystem) {}

    public function sourceCompilers(): array
    {
        return [LookupSource::class => $this->compile(...)];
    }

    private function compile(Source $source, SourceCompilation $compilation): CompiledSource
    {
        $source = instance_of(LookupSource::class)->assert($source);
        $compiledFilters = [];

        foreach ($source->filters as $filter) {
            $value = $compilation->child($filter->value);
            $compiledFilters[] = $this->compileFilter($source->table, $filter, $value, $compilation);
        }

        $compiledResult = $this->compileResult($source->table, $source->result, $compilation);

        return $compilation->custom(
            $compiledResult->returns,
            fn(SourceEvaluation $evaluation): Result => $this->evaluate(
                $source,
                $compiledResult,
                $evaluation,
                $compiledFilters,
            ),
        );
    }

    private function compileResult(
        DelimitedTable $table,
        ProjectedResult|NumericResult $result,
        SourceCompilation $compilation,
    ): CompiledLookupResult {
        if ($result instanceof ProjectedResult) {
            return $this->compileProjectedResult($table, $result, $compilation);
        }

        return $this->compileNumericResult($table, $result, $compilation);
    }

    private function compileProjectedResult(
        DelimitedTable $table,
        ProjectedResult $result,
        SourceCompilation $compilation,
    ): CompiledLookupResult {
        $projected = $this->projectionType($table, $result->projection, $compilation);

        if ($result->rows instanceof AllRows) {
            return new CompiledLookupResult(new ListType($projected));
        }

        if ($result->rows instanceof FirstRow || $result->rows instanceof LastRow) {
            return new CompiledLookupResult($this->optionalProjection($projected));
        }

        $column = $this->requireColumn($table, $result->rows->column, $compilation);
        $type = PresentType::of($column->type);
        $operator = $result->rows instanceof MinimumRow ? '<' : '>';
        $ordering = $this->booleanInfix($compilation, $type, $operator, $type);

        return new CompiledLookupResult($this->optionalProjection($projected), $ordering);
    }

    private function compileNumericResult(
        DelimitedTable $table,
        NumericResult $result,
        SourceCompilation $compilation,
    ): CompiledLookupResult {
        if ($result->fold instanceof CountRows) {
            return new CompiledLookupResult(new NumberType());
        }

        $column = $this->requireColumn($table, $result->fold->column, $compilation);
        $number = new NumberType();
        $assignable = TypeRelations::isTypeAssignableTo(PresentType::of($column->type), $number);

        if ($assignable->isErr()) {
            $compilation->reject(new TypeMismatch(sprintf(
                'Column [%s] used by [%s] must be Number; it is declared %s.',
                $column->identity,
                $result->kind()->value,
                TypeDescriber::describe($column->type),
            ), [$assignable->unwrapErr()]));
        }

        return new CompiledLookupResult(new OptionType($number));
    }

    private function projectionType(
        DelimitedTable $table,
        ValueProjection|RecordProjection $projection,
        SourceCompilation $compilation,
    ): Type {
        if ($projection instanceof ValueProjection) {
            return $this->requireColumn($table, $projection->column, $compilation)->type;
        }

        $fields = [];

        foreach ($projection->fields as $field => $identity) {
            $fields[$field] = $this->requireColumn($table, $identity, $compilation)->type;
        }

        return new RecordType($fields);
    }

    private function optionalProjection(Type $projected): Type
    {
        return $projected->shape() instanceof OptionShape ? $projected : new OptionType($projected);
    }

    private function compileFilter(
        DelimitedTable $table,
        Filter $filter,
        CompiledSource $value,
        SourceCompilation $compilation,
    ): CompiledFilter {
        if ($filter instanceof ValueFilter) {
            $column = $this->requireColumn($table, $filter->column, $compilation);
            $cellType = PresentType::of($column->type);
            $operation = $this->booleanInfix($compilation, $cellType, $filter->operator, $value->returns);

            return new CompiledFilter(
                $value,
                fn(CsvRecord $record, mixed $resolved): Result => $this->matchValue(
                    $table,
                    $record,
                    $column,
                    $resolved,
                    $operation,
                ),
            );
        }

        if ($filter instanceof RangeFilter) {
            $minimum = $this->requireColumn($table, $filter->minColumn, $compilation);
            $maximum = $this->requireColumn($table, $filter->maxColumn, $compilation);
            $minimumOperation = $this->booleanInfix(
                $compilation,
                $value->returns,
                '>=',
                PresentType::of($minimum->type),
            );
            $maximumOperation = $this->booleanInfix(
                $compilation,
                $value->returns,
                '<',
                PresentType::of($maximum->type),
            );

            return new CompiledFilter(
                $value,
                fn(CsvRecord $record, mixed $resolved): Result => $this->matchRange(
                    $table,
                    $record,
                    $minimum,
                    $maximum,
                    $resolved,
                    $minimumOperation,
                    $maximumOperation,
                ),
            );
        }

        $compilation->reject(sprintf('Filter [%s] has no compiler in LookupExtension.', $filter::class));
    }

    private function requireColumn(
        DelimitedTable $table,
        string|int $identity,
        SourceCompilation $compilation,
    ): Column {
        $column = $table->declaration($identity);

        if ($column === null) {
            $compilation->reject(sprintf(
                'Column [%s] is referenced but not declared by table [%s].',
                $identity,
                $table->path,
            ));
        }

        return $column;
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
                'Lookup operator [%s] must return Boolean.',
                $operator,
            ), [$assignable->unwrapErr()]));
        }

        return $operation;
    }

    /** @return Result<bool, Throwable> */
    private function matchValue(
        DelimitedTable $table,
        CsvRecord $record,
        Column $column,
        mixed $value,
        BoundOperation $operation,
    ): Result {
        return $this->admitColumn($table, $record, $column)
            ->andThen(fn(mixed $cell): Result => $cell === null
                ? Ok(false)
                : $this->evaluateBoolean($operation, $cell, $value));
    }

    /** @return Result<bool, Throwable> */
    private function matchRange(
        DelimitedTable $table,
        CsvRecord $record,
        Column $minimum,
        Column $maximum,
        mixed $value,
        BoundOperation $minimumOperation,
        BoundOperation $maximumOperation,
    ): Result {
        $minimumValue = $this->admitColumn($table, $record, $minimum);

        if ($minimumValue->isErr()) {
            return $minimumValue;
        }

        $maximumValue = $this->admitColumn($table, $record, $maximum);

        if ($maximumValue->isErr()) {
            return $maximumValue;
        }

        if ($minimumValue->unwrap() === null || $maximumValue->unwrap() === null) {
            return Ok(false);
        }

        $aboveMinimum = $this->evaluateBoolean($minimumOperation, $value, $minimumValue->unwrap());

        if ($aboveMinimum->isErr() || $aboveMinimum->unwrap() === false) {
            return $aboveMinimum;
        }

        return $this->evaluateBoolean($maximumOperation, $value, $maximumValue->unwrap());
    }

    /** @return Result<mixed, Throwable> */
    private function admitColumn(DelimitedTable $table, CsvRecord $record, Column $column): Result
    {
        $hasValue = $record->has($column->identity);

        if (! $hasValue && ! $column->type->shape() instanceof OptionShape) {
            return Err($this->missingValue($table, $column));
        }

        $raw = $hasValue ? $record->get($column->identity) : null;

        return $this->castCell($raw, $column->type)
            ->mapErr(fn(Throwable $error) => new RuntimeException(sprintf(
                'Column [%s] of [%s] is declared %s, but a matching row holds [%s].',
                $column->identity,
                $table->path,
                TypeDescriber::describe($column->type),
                TransformValueException::format($raw),
            ), previous: $error))
            ->andThen(fn(Option $value): Result => $value->mapOr(
                default: Err($this->missingValue($table, $column)),
                f: fn(mixed $admitted): Result => Ok($admitted),
            ));
    }

    private function missingValue(DelimitedTable $table, Column $column): RuntimeException
    {
        return new RuntimeException(sprintf(
            'Column [%s] of [%s] is declared %s, but a matching row has no value for it.',
            $column->identity,
            $table->path,
            TypeDescriber::describe($column->type),
        ));
    }

    /** @return Result<Option<mixed>, Throwable> */
    private function castCell(mixed $value, Type $type): Result
    {
        return $type::class === StringType::class && is_string($value)
            ? $type->assert($value)
            : $type->coerce($value);
    }

    /** @return Result<bool, Throwable> */
    private function evaluateBoolean(BoundOperation $operation, mixed $left, mixed $right): Result
    {
        /** @var bool $result */
        $result = $operation($left, $right);

        return Ok($result);
    }

    /**
     * @param list<CompiledFilter> $compiledFilters
     * @return Result<mixed, Throwable>
     */
    private function evaluate(
        LookupSource $source,
        CompiledLookupResult $compiledResult,
        SourceEvaluation $evaluation,
        array $compiledFilters,
    ): Result {
        $table = $source->table;
        $evaluation->annotate('result', $source->result->kind()->value);

        if ($source->result instanceof ProjectedResult) {
            $evaluation->annotate('projection', $this->describeProjection($source->result->projection));
        }

        $resolvedFilters = $this->resolveFilters($compiledFilters, $evaluation);
        $stream = null;

        try {
            $records = attempt(function () use ($table, &$stream): iterable {
                $stream = $this->filesystem->readStream($table->path);

                if ($stream === false) {
                    throw new RuntimeException("Could not open file: {$table->path}");
                }

                $reader = Reader::from($stream);
                $reader->setDelimiter($table->delimiter);

                if ($table->hasHeader) {
                    $reader->setHeaderOffset(0);
                    $this->assertDeclaredHeaders($table, $reader->getHeader());
                }

                return $table->hasHeader ? $reader->getRecords() : $reader->getRecords([]);
            });

            if ($records->isErr()) {
                return $records;
            }

            $selected = null;
            $selectedRows = [];
            $selectedOrder = null;
            $count = 0;
            $sum = 0.0;
            $numericValues = 0;

            foreach ($records->unwrap() as $record) {
                /** @var array<string|int, mixed> $record */
                $csvRecord = CsvRecord::from($record);
                $filterResult = $this->matchesAllFilters($csvRecord, $resolvedFilters);

                if ($filterResult->isErr()) {
                    return $filterResult;
                }

                if ($filterResult->unwrap() === false) {
                    continue;
                }

                if ($source->result instanceof ProjectedResult) {
                    $accumulated = $this->selectRow(
                        $table,
                        $source->result,
                        $compiledResult,
                        $csvRecord,
                        $selected,
                        $selectedRows,
                        $selectedOrder,
                    );

                    if ($accumulated->isErr()) {
                        return $accumulated;
                    }

                    /** @var array{?CsvRecord, list<CsvRecord>, mixed, bool} $state */
                    $state = $accumulated->unwrap();
                    [$selected, $selectedRows, $selectedOrder, $earlyExit] = $state;

                    if ($earlyExit) {
                        break;
                    }

                    continue;
                }

                $folded = $this->foldNumber($table, $source->result, $csvRecord, $count, $sum, $numericValues);

                if ($folded->isErr()) {
                    return $folded;
                }

                /** @var array{int, float, int} $numericState */
                $numericState = $folded->unwrap();
                [$count, $sum, $numericValues] = $numericState;
            }

            $evaluation->annotate('label', $table->path);

            return $source->result instanceof ProjectedResult
                ? $this->finishProjection($table, $source->result, $selected, $selectedRows)
                : $this->finishNumber($source->result, $count, $sum, $numericValues);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** @return string|int|array<string, string|int> */
    private function describeProjection(ValueProjection|RecordProjection $projection): string|int|array
    {
        if ($projection instanceof ValueProjection) {
            return $projection->column;
        }

        return $projection->fields;
    }

    /** @param array<string> $header */
    private function assertDeclaredHeaders(DelimitedTable $table, array $header): void
    {
        foreach ($table->columns as $column) {
            if (! in_array($column->identity, $header, strict: true)) {
                throw new RuntimeException(sprintf(
                    'Column [%s] is declared by table [%s], but its header is missing.',
                    $column->identity,
                    $table->path,
                ));
            }
        }
    }

    /**
     * @param list<CsvRecord> $selectedRows
     * @return Result<array{?CsvRecord, list<CsvRecord>, mixed, bool}, Throwable>
     */
    private function selectRow(
        DelimitedTable $table,
        ProjectedResult $result,
        CompiledLookupResult $compiled,
        CsvRecord $record,
        ?CsvRecord $selected,
        array $selectedRows,
        mixed $selectedOrder,
    ): Result {
        if ($result->rows instanceof FirstRow) {
            return Ok([$record, $selectedRows, $selectedOrder, true]);
        }

        if ($result->rows instanceof LastRow) {
            return Ok([$record, $selectedRows, $selectedOrder, false]);
        }

        if ($result->rows instanceof AllRows) {
            return Ok([$selected, [...$selectedRows, $record], $selectedOrder, false]);
        }

        $column = $table->requireDeclaration($result->rows->column);
        $ordering = $compiled->requireOrdering();

        return $this->admitColumn($table, $record, $column)
            ->andThen(function (mixed $order) use (
                $ordering,
                $record,
                $selected,
                $selectedRows,
                $selectedOrder,
            ): Result {
                if ($order === null) {
                    return Ok([$selected, $selectedRows, $selectedOrder, false]);
                }

                if ($selected === null) {
                    return Ok([$record, $selectedRows, $order, false]);
                }

                return $this->evaluateBoolean($ordering, $order, $selectedOrder)
                    ->map(fn(bool $replace): array => $replace
                        ? [$record, $selectedRows, $order, false]
                        : [$selected, $selectedRows, $selectedOrder, false]);
            });
    }

    /** @return Result<array{int, float, int}, Throwable> */
    private function foldNumber(
        DelimitedTable $table,
        NumericResult $result,
        CsvRecord $record,
        int $count,
        float $sum,
        int $numericValues,
    ): Result {
        if ($result->fold instanceof CountRows) {
            return Ok([$count + 1, $sum, $numericValues]);
        }

        $column = $table->requireDeclaration($result->fold->column);

        return $this->admitColumn($table, $record, $column)
            ->andThen(function (mixed $value) use ($count, $sum, $numericValues): Result {
                if ($value === null) {
                    return Ok([$count, $sum, $numericValues]);
                }

                if (! is_int($value) && ! is_float($value)) {
                    return Err(new RuntimeException('A numeric fold admitted a non-numeric value.'));
                }

                return Ok([$count, $sum + $value, $numericValues + 1]);
            });
    }

    /**
     * @param list<CsvRecord> $selectedRows
     * @return Result<mixed, Throwable>
     */
    private function finishProjection(
        DelimitedTable $table,
        ProjectedResult $result,
        ?CsvRecord $selected,
        array $selectedRows,
    ): Result {
        if ($result->rows instanceof AllRows) {
            return Result::collect(map(
                $selectedRows,
                fn(CsvRecord $record): Result => $this->project($table, $result->projection, $record),
            ));
        }

        return $selected === null ? Ok(null) : $this->project($table, $result->projection, $selected);
    }

    /** @return Result<mixed, Throwable> */
    private function project(
        DelimitedTable $table,
        ValueProjection|RecordProjection $projection,
        CsvRecord $record,
    ): Result {
        if ($projection instanceof ValueProjection) {
            return $this->admitColumn(
                $table,
                $record,
                $table->requireDeclaration($projection->column),
            );
        }

        $projected = [];

        foreach ($projection->fields as $field => $identity) {
            $column = $table->requireDeclaration($identity);

            $value = $this->admitColumn($table, $record, $column);

            if ($value->isErr()) {
                return $value;
            }

            $projected[$field] = $value->unwrap();
        }

        return Ok($projected);
    }

    /** @return Result<int|float|null, Throwable> */
    private function finishNumber(NumericResult $result, int $count, float $sum, int $numericValues): Result
    {
        if ($result->fold instanceof CountRows) {
            return Ok($count);
        }

        if ($numericValues === 0) {
            return Ok(null);
        }

        if ($result->fold instanceof SumColumn) {
            return Ok($sum);
        }

        return Ok($sum / $numericValues);
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
     * @param list<ResolvedFilter> $resolvedFilters
     * @return Result<bool, Throwable>
     */
    private function matchesAllFilters(CsvRecord $record, array $resolvedFilters): Result
    {
        foreach ($resolvedFilters as $resolvedFilter) {
            $result = $resolvedFilter->matches($record);

            if ($result->isErr()) {
                return $result;
            }

            if ($result->unwrap() === false) {
                return Ok(false);
            }
        }

        return Ok(true);
    }
}
