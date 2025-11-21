<?php

declare(strict_types=1);

namespace Superscript\Schema\Operators;

use UnhandledMatchError;

final readonly class DefaultOverloader implements OperatorOverloader
{
    /**
     * @var list<OperatorOverloader>
     */
    private array $overloaders;

    public function __construct()
    {
        $this->overloaders = [
            new BinaryOverloader(),
            new ComparisonOverloader(),
            new HasOverloader(),
            new InOverloader(),
            new LogicalOverloader(),
            new IntersectsOverloader(),
        ];
    }

    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        foreach ($this->overloaders as $overloader) {
            if ($overloader->supportsOverloading($left, $right, $operator)) {
                return true;
            }
        }

        return false;
    }

    public function evaluate(mixed $left, mixed $right, string $operator): mixed
    {
        foreach ($this->overloaders as $overloader) {
            if ($overloader->supportsOverloading($left, $right, $operator)) {
                return $overloader->evaluate($left, $right, $operator);
            }
        }

        throw new UnhandledMatchError("Operator [$operator] is not supported.");
    }
}
