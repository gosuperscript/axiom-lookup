<?php

declare(strict_types=1);

namespace Superscript\Schema\Operators;

interface OperatorOverloader
{
    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool;

    public function evaluate(mixed $left, mixed $right, string $operator): mixed;
}
