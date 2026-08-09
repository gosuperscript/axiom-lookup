<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Results;

final readonly class ValueProjection
{
    public function __construct(public string|int $column) {}
}
