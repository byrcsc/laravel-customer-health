<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Exceptions;

final class InvalidEventDefinitionException extends CustomerHealthException
{
    public static function forClass(string $eventClass): self
    {
        return new self("Registered event [{$eventClass}] must extend ProductEvent.");
    }

    public static function forDuplicateName(string $name): self
    {
        return new self("Product event name [{$name}] is registered more than once.");
    }
}
