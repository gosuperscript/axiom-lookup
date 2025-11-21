<?php

declare(strict_types=1);

namespace Superscript\Schema\Resolvers;

use Superscript\Schema\Source;
use Superscript\Schema\Sources\StaticSource;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\None;
use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

/**
 * @implements Resolver<StaticSource>
 */
final readonly class StaticResolver implements Resolver
{
    public function resolve(Source $source): Result
    {
        return Ok(is_null($source->value) ? None() : Some($source->value));
    }
}
