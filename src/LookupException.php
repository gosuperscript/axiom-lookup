<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup;

use RuntimeException;

final class LookupException extends RuntimeException
{
    public static function undefinedAggregateColumn(string $aggregate): self
    {
        return new self("Undefined aggregate column for '{$aggregate}' aggregate");
    }

    public static function nonNumericValue(string|int $column, string $aggregate): self
    {
        return new self("Non-numeric value encountered in column '{$column}' for '{$aggregate}' aggregate");
    }

    public static function unexpectedRowCount(int $expectedRowCount, int $count): self
    {
        return new self("Expected exactly {$expectedRowCount} record(s), {$count} record(s) found.");
    }

    public static function unknownAggregate(string $aggregate): self
    {
        return new self("Unknown aggregate: {$aggregate}");
    }

    public static function fileNotFound(string $path): self
    {
        return new self("Could not open file: {$path}");
    }
}
