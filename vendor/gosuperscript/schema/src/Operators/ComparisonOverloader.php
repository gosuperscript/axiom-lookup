<?php

declare(strict_types=1);

namespace Superscript\Schema\Operators;

final readonly class ComparisonOverloader implements OperatorOverloader
{
    private const operators = ['=', '==', '===', '!=', '!==', '<', '<=', '>', '>='];

    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return in_array($operator, self::operators);
    }

    /**
     * @param value-of<self::operators> $operator
     */
    public function evaluate(mixed $left, mixed $right, string $operator): mixed
    {
        return match ($operator) {
            '=', '==' => $left == $right,
            '===' => $left === $right,
            '!=' => $left != $right,
            '!==' => $left !== $right,
            '<' => $left < $right,
            '<=' => $left <= $right,
            '>' => $left > $right,
            '>=' => $left >= $right,
        };
    }
}
