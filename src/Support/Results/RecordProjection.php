<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Results;

use InvalidArgumentException;

final readonly class RecordProjection
{
    /**
     * @param array<string, string|int> $fields
     */
    public function __construct(public array $fields)
    {
        if ($fields === []) {
            throw new InvalidArgumentException('A record projection requires at least one field.');
        }

        foreach ($fields as $field => $column) {
            if (! is_string($field)) {
                throw new InvalidArgumentException('Record projection field names must be strings.');
            }

            if (! is_string($column) && ! is_int($column)) {
                throw new InvalidArgumentException('Record projection columns must be named or positional identities.');
            }
        }
    }

}
