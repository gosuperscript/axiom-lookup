<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests\Resolvers\Fixtures;

use Superscript\Schema\Resolvers\Resolver;
use Superscript\Schema\Source;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

final readonly class ResolverWithDependency implements Resolver
{
    public function __construct(
        private Dependency $dependency,
    ) {}

    public function resolve(Source $source): Result
    {
        return Ok(Some($this->dependency->info));
    }
}
