<?php

declare(strict_types=1);

namespace Superscript\Schema\Operators;

class IntersectsOverloader implements OperatorOverloader
{
    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return $operator === 'intersects';
    }

    /**
     * @param 'in' $operator
     */
    public function evaluate(mixed $left, mixed $right, string $operator): bool
    {
        $left = is_array($left) ? $left : [$left];
        $right = is_array($right) ? $right : [$right];

        return count(array_intersect($left, $right)) > 0;
    }
}
