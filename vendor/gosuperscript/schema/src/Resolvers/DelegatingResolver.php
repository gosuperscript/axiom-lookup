<?php

declare(strict_types=1);

namespace Superscript\Schema\Resolvers;

use Illuminate\Container\Container;
use RuntimeException;
use Superscript\Schema\Source;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

final readonly class DelegatingResolver implements Resolver
{
    protected Container $container;

    /**
     * @param array<class-string<Source>, class-string<Resolver>> $resolverMap
     */
    public function __construct(public array $resolverMap = [])
    {
        $this->container = new Container();
        $this->container->instance(Resolver::class, $this);

        foreach ($this->resolverMap as $resolver) {
            $this->container->bind($resolver, $resolver);
        }
    }

    /**
     * @param class-string $key
     */
    public function instance(string $key, mixed $concrete): void
    {
        $this->container->instance($key, $concrete);
    }

    /**
     * @return Result<Option<mixed>, Throwable>
     */
    public function resolve(Source $source): Result
    {
        $sourceClass = get_class($source);
        
        if (isset($this->resolverMap[$sourceClass]) && $this->container->has($this->resolverMap[$sourceClass])) {
            return $this->container->make($this->resolverMap[$sourceClass])->resolve($source);
        }

        throw new RuntimeException("No resolver found for source of type " . $sourceClass);
    }
}
