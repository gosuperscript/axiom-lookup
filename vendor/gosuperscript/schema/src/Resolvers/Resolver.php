<?php

declare(strict_types=1);

namespace Superscript\Schema\Resolvers;

use Superscript\Schema\Source;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

/**
 * @template T of Source = Source
 */
interface Resolver
{
    /**
     * @phpstan-param T $source
     * @return Result<Option<mixed>, Throwable>
     */
    public function resolve(Source $source): Result;
}
