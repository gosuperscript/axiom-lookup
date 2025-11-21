<?php

declare(strict_types=1);

namespace Superscript\Schema\Resolvers;

use Superscript\Schema\Source;
use Superscript\Schema\Sources\ValueDefinition;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

/**
 * @implements Resolver<ValueDefinition>
 */
final readonly class ValueResolver implements Resolver
{
    public function __construct(
        private Resolver $resolver,
    ) {}

    /**
     * @return Result<Option<mixed>, mixed>
     */
    public function resolve(Source $source): Result
    {
        return $this->resolver->resolve($source->source)
            ->andThen(
                fn(Option $option) => $option
                ->andThen(fn(mixed $result) => $source->type->coerce($result)->transpose())
                ->transpose(),
            );
    }
}
