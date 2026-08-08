<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Exceptions;

use ByRcsc\LaravelCustomerHealth\Scoring\Signal;

final class InvalidSignalValueException extends CustomerHealthException
{
    /** @param class-string<Signal> $signal */
    public static function outsideRange(string $signal, int $value): self
    {
        return new self("Signal [{$signal}] returned [{$value}]; signal values must be between 0 and 100.");
    }
}
