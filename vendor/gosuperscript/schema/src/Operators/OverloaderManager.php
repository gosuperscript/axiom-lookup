<?php

declare(strict_types=1);

namespace Superscript\Schema\Operators;

use RuntimeException;
use SebastianBergmann\Exporter\Exporter;
use Webmozart\Assert\Assert;

class OverloaderManager implements OperatorOverloader
{
    public function __construct(
        /** @var list<OperatorOverloader> */
        private array $overloaders,
    ) {
        Assert::allIsInstanceOf($this->overloaders, OperatorOverloader::class);
    }

    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return (bool) $this->getOverloader($left, $right, $operator);
    }

    public function evaluate(mixed $left, mixed $right, string $operator): mixed
    {
        if ($overloader = $this->getOverloader($left, $right, $operator)) {
            return $overloader->evaluate($left, $right, $operator);
        }

        throw new RuntimeException(sprintf('No overloader found for [%s] %s [%s]', (new Exporter())->export($left), $operator, (new Exporter())->export($right)));
    }

    private function getOverloader(mixed $left, mixed $right, string $operator): ?OperatorOverloader
    {
        foreach ($this->overloaders as $overloader) {
            if ($overloader->supportsOverloading($left, $right, $operator)) {
                return $overloader;
            }
        }

        return null;
    }
}
