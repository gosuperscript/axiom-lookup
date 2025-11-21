<?php

namespace Superscript\Schema\Sources;

use Superscript\Schema\Source;

final readonly class UnaryExpression implements Source
{
    public function __construct(
        public string $operator,
        public Source $operand,
    ) {
    }
}