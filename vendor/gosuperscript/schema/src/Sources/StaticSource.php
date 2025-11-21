<?php

declare(strict_types=1);

namespace Superscript\Schema\Sources;

use Superscript\Schema\Source;

final readonly class StaticSource implements Source
{
    public function __construct(
        public mixed $value,
    ) {}
}
