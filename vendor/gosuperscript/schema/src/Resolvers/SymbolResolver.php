<?php

declare(strict_types=1);

namespace Superscript\Schema\Resolvers;

use Superscript\Schema\Source;
use Superscript\Schema\Sources\SymbolSource;
use Superscript\Schema\SymbolRegistry;
use Superscript\Monads\Result\Result;

final readonly class SymbolResolver implements Resolver
{
    public function __construct(
        public Resolver $resolver,
        public SymbolRegistry $symbolRegistry,
    ) {}

    /**
     * @param SymbolSource $source
     */
    public function resolve(Source $source): Result
    {
        return $this->symbolRegistry->get($source->name, $source->namespace)
            ->andThen(fn(Source $source) => $this->resolver->resolve($source)->transpose())->transpose();
    }
}
