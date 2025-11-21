<?php

declare(strict_types=1);

namespace Superscript\Schema\Types;

use InvalidArgumentException;
use Superscript\Monads\Option\Option;
use Superscript\Schema\Exceptions\TransformValueException;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Result;

use function Psl\Vec\map;
use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * @implements Type<List<mixed>>
 */
class ListType implements Type
{
    public function __construct(
        public Type $type,
    ) {}

    public function assert(mixed $value): Result
    {
        if (!is_array($value)) {
            return new Err(new TransformValueException(
                type: 'list',
                value: $value,
            ));
        }

        return Result::collect(map($value, function (mixed $item) {
            return $this->type->assert($item)->andThen(fn(Option $value) => $value->mapOr(
                default: Err(new InvalidArgumentException('List item can not be a None')),
                f: fn(mixed $value) => Ok($value),
            ));
        }))->map(fn(array $items) => Some($items));
    }

    public function coerce(mixed $value): Result
    {
        if (is_string($value) && json_validate($value) && $decoded = \Psl\Json\decode($value)) {
            $value = $decoded;
        }

        if (!is_array($value)) {
            return new Err(new TransformValueException(
                type: 'list',
                value: $value,
            ));
        }

        return Result::collect(map($value, function (mixed $item) {
            return $this->type->coerce($item)->andThen(fn(Option $value) => $value->mapOr(
                default: Err(new InvalidArgumentException('List item can not be a None')),
                f: fn(mixed $value) => Ok($value),
            ));
        }))->map(fn(array $items) => Some($items));
    }

    public function compare(mixed $a, mixed $b): bool
    {
        return count($a) === count($b) && array_all(
            array_keys($a),
            fn(int|string $key) => $this->type->compare($a[$key], $b[$key])
        );
    }

    public function format(mixed $value): string
    {
        return implode(', ', array_map(fn(mixed $item) => $this->type->format($item), $value));
    }
}
