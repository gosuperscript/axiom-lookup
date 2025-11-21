<?php

declare(strict_types=1);

namespace Superscript\Schema\Types;

use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

/**
 * @template T = mixed
 */
interface Type
{
    /**
     * Assert that a value is of type T and return it wrapped in Option
     * @param T $value
     * @return Result<Option<T>, Throwable>
     */
    public function assert(mixed $value): Result;

    /**
     * Try to coerce a mixed value into type T
     * @param mixed $value
     * @return Result<Option<T>, Throwable>
     */
    public function coerce(mixed $value): Result;

    /**
     * @param T $a
     * @param T $b
     * @return bool
     */
    public function compare(mixed $a, mixed $b): bool;

    /**
     * @param T $value
     * @return string
     */
    public function format(mixed $value): string;
}
