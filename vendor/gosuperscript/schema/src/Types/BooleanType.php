<?php

declare(strict_types=1);

namespace Superscript\Schema\Types;

use Stringable;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Ok;
use Superscript\Monads\Result\Result;
use Superscript\Schema\Exceptions\TransformValueException;
use function Superscript\Monads\Option\None;
use function Superscript\Monads\Option\Some;

/**
 * @implements Type<bool>
 */
final class BooleanType implements Type
{
    public function assert(mixed $value): Result
    {
        if (!is_bool($value)) {
            return new Err(new TransformValueException(type: 'boolean', value: $value));
        }

        return new Ok(Some($value));
    }

    public function coerce(mixed $value): Result
    {
        return (match (true) {
            is_bool($value) => new Ok($value),
            in_array($value, ['yes', 'on', '1', 1, 'true', 'TRUE'], strict: true) => new Ok(true),
            in_array($value, ['no', 'off', '0', 0, 'false', 'FALSE', null], strict: true) => new Ok(false),
            default => new Err(new TransformValueException(type: 'boolean', value: $value)),
        })->map(fn(bool $value) => Some($value));
    }

    public function compare(mixed $a, mixed $b): bool
    {
        return $a === $b;
    }

    public function format(mixed $value): string
    {
        return $value ? 'True' : 'False';
    }
}
