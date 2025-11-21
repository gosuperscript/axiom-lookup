<?php

declare(strict_types=1);

namespace Superscript\Schema\Exceptions;

use RuntimeException;
use SebastianBergmann\Exporter\Exporter;
use Throwable;

class TransformValueException extends RuntimeException
{
    public function __construct(string $type, mixed $value, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('Unable to transform into [%s] from [%s]', $type, self::format($value)), previous: $previous);
    }

    public static function format(mixed $value): string
    {
        return new Exporter()->shortenedExport($value instanceof \Stringable ? (string) $value : $value);
    }
}
