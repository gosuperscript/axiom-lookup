<?php

declare(strict_types=1);

namespace Superscript\Schema\Operators;

use function Psl\Type\mixed_vec;

final readonly class HasOverloader implements OperatorOverloader
{
    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return $operator === 'has' && is_array($left);
    }

    public function evaluate(mixed $left, mixed $right, string $operator): mixed
    {
        $left = mixed_vec()->coerce($left);
        return is_array($right) ? array_intersect($right, $left) === $right : in_array($right, $left);
    }
}
