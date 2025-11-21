<?php

declare(strict_types=1);

namespace Superscript\Schema\Operators;

final readonly class BinaryOverloader implements OperatorOverloader
{
    private const operators = ['+', '-', '*', '/'];

    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return is_numeric($left) && is_numeric($right) && in_array($operator, self::operators);
    }

    /**
     * @param numeric $left
     * @param numeric $right
     * @param value-of<self::operators> $operator
     */
    public function evaluate(mixed $left, mixed $right, string $operator): mixed
    {
        return match ($operator) {
            '+' => $left + $right,
            '-' => $left - $right,
            '*' => $left * $right,
            '/' => $left / $right,
        };
    }
}
