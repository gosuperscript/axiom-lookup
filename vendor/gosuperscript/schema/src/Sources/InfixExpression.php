<?php

declare(strict_types=1);

namespace Superscript\Schema\Sources;

use Superscript\Schema\Source;

final readonly class InfixExpression implements Source
{
    public function __construct(
        public Source $left,
        public string $operator,
        public Source $right,
    ) {}
}
