<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Results;

use LogicException;
use Superscript\Axiom\BoundOperation;
use Superscript\Axiom\Types\Type;

/** @internal The type and optional ordering operation certified at compilation. */
final readonly class CompiledLookupResult
{
    public function __construct(
        public Type $returns,
        public ?BoundOperation $ordering = null,
    ) {}

    public function requireOrdering(): BoundOperation
    {
        if ($this->ordering === null) {
            throw new LogicException('Compiled row ordering is missing.');
        }

        return $this->ordering;
    }
}
