<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup;

use InvalidArgumentException;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\UnknownType;

/** A named or positional column declaration in a delimited table. */
final readonly class Column
{
    public function __construct(
        public string|int $identity,
        public Type $type,
    ) {
        if ($type instanceof UnknownType) {
            throw new InvalidArgumentException('Unknown is derived and cannot be used as a column declaration.');
        }
    }
}
