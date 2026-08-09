<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Results;

final readonly class ProjectedResult
{
    public function __construct(
        public FirstRow|LastRow|AllRows|MinimumRow|MaximumRow $rows,
        public ValueProjection|RecordProjection $projection,
    ) {}

    public function kind(): LookupResultKind
    {
        return $this->rows->kind();
    }
}
