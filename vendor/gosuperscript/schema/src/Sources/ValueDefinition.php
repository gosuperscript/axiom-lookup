<?php

declare(strict_types=1);

namespace Superscript\Schema\Sources;

use Superscript\Schema\Source;
use Superscript\Schema\Types\Type;

final readonly class ValueDefinition implements Source
{
    public function __construct(
        public Type $type,
        public Source $source,
    ) {}
}
