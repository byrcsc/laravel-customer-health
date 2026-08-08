<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Exceptions;

final class InvalidEventPropertiesException extends CustomerHealthException
{
    public static function nonPrimitive(string $path): self
    {
        return new self("Product event property [{$path}] must contain only JSON primitives and arrays.");
    }
}
