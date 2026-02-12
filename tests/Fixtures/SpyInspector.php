<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Tests\Fixtures;

use Superscript\Axiom\ResolutionInspector;

final class SpyInspector implements ResolutionInspector
{
    /** @var array<string, mixed> */
    public array $annotations = [];

    public function annotate(string $key, mixed $value): void
    {
        $this->annotations[$key] = $value;
    }
}
