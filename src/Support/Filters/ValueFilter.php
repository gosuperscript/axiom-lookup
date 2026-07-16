<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Filters;

use Superscript\Axiom\Source;

/**
 * Matches a single column against a comparison value.
 *
 * LookupExtension resolves the operator once against the column type from
 * LookupSource::$schema (String by default) and the compiled value's type.
 * Evaluation therefore uses exactly the same dialect operation as an Axiom
 * InfixExpression, including extension-owned rules and diagnostics.
 */
final readonly class ValueFilter implements Filter
{
    public function __construct(
        public string|int $column,
        public Source $value,
        public string $operator = '==',
    ) {}
}
