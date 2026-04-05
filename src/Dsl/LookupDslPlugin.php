<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Dsl;

use Superscript\Axiom\Dsl\DslLiteralExtension;
use Superscript\Axiom\Dsl\DslPlugin;
use Superscript\Axiom\Dsl\FunctionParam;
use Superscript\Axiom\Dsl\FunctionRegistry;
use Superscript\Axiom\Dsl\OperatorRegistry;
use Superscript\Axiom\Dsl\TypeRegistry;
use Superscript\Axiom\Lookup\LookupSource;
use Superscript\Axiom\Lookup\Support\Filters\ValueFilter;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Patterns\PatternMatcher;
use Superscript\Axiom\Source;

final class LookupDslPlugin implements DslPlugin
{
    public function operators(OperatorRegistry $operators): void {}

    public function types(TypeRegistry $types): void {}

    public function functions(FunctionRegistry $functions): void
    {
        $functions->register('lookup', [
            new FunctionParam('path', 'string'),
            new FunctionParam('column', 'string'),
        ], function (array $args, mixed $compiler): LookupSource {
            /** @var string $path */
            $path = $compiler->expectStaticString($args[0]);
            /** @var string $column */
            $column = $compiler->expectStaticString($args['column']);

            $filters = [];

            foreach ($args as $key => $value) {
                if (is_string($key) && $key !== 'column') {
                    /** @var Source $compiled */
                    $compiled = $compiler->compile($value);
                    $filters[] = new ValueFilter($key, $compiled);
                }
            }

            return new LookupSource(path: $path, filters: $filters, columns: [$column]);
        });
    }

    /** @return list<PatternMatcher> */
    public function patterns(): array
    {
        return [];
    }

    /** @return list<DslLiteralExtension> */
    public function literals(): array
    {
        return [];
    }

    /** @return list<OperatorOverloader> */
    public function overloaders(): array
    {
        return [];
    }
}
