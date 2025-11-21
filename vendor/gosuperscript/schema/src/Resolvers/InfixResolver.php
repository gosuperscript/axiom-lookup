<?php

declare(strict_types=1);

namespace Superscript\Schema\Resolvers;

use Superscript\Schema\Operators\DefaultOverloader;
use Superscript\Schema\Operators\OperatorOverloader;
use Superscript\Schema\Operators\OverloaderManager;
use Superscript\Schema\Source;
use Superscript\Schema\Sources\InfixExpression;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

/**
 * @implements Resolver<InfixExpression>
 */
final readonly class InfixResolver implements Resolver
{
    public function __construct(
        public Resolver $resolver,
    ) {}

    public function resolve(Source $source): Result
    {
        return $this->resolver->resolve($source->left)
            ->andThen(fn(Option $left) => $this->resolver->resolve($source->right)->map(fn(Option $right) => [$left, $right]))
            ->map(function (array $option) use ($source) {
                [$left, $right] = $option;

                $result = $this->getOperatorOverloader()->evaluate($left->unwrapOr(null), $right->unwrapOr(null), $source->operator);
                return Option::from($result);
            });
    }

    private function getOperatorOverloader(): OperatorOverloader
    {
        return new OverloaderManager([
            new DefaultOverloader(),
        ]);
    }
}
