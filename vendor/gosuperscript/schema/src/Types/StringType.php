<?php

declare(strict_types=1);

namespace Superscript\Schema\Types;

use Stringable;
use Superscript\Schema\Exceptions\TransformValueException;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Ok;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\None;
use function Superscript\Monads\Option\Some;

/**
 * @implements Type<string>
 */
class StringType implements Type
{
    public function assert(mixed $value): Result
    {
        if (!is_string($value)) {
            return new Err(new TransformValueException(type: 'string', value: $value));
        }

        return new Ok(match (true) {
            $value === 'null' => None(),
            strlen($value) === 0 => None(),
            default => Some($value),
        });
    }

    public function coerce(mixed $value): Result
    {
        return match (true) {
            is_string($value) => new Ok(match (true) {
                $value === 'null' => None(),
                strlen($value) === 0 => None(),
                default => Some($value),
            }),
            is_numeric($value) => new Ok(Some(strval($value))),
            $value instanceof Stringable => new Ok(Some((string) $value)),
            default => new Err(new TransformValueException(type: 'string', value: $value)),
        };
    }

    public function compare(mixed $a, mixed $b): bool
    {
        return $a === $b;
    }

    public function format(mixed $value): string
    {
        return strval($value);
    }
}
