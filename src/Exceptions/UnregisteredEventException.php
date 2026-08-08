<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Exceptions;

final class UnregisteredEventException extends CustomerHealthException
{
    /**
     * @param  class-string  $eventClass
     */
    public static function forClass(string $eventClass): self
    {
        return new self("Product event [{$eventClass}] is not registered.");
    }

    public static function forName(string $name): self
    {
        return new self("Product event [{$name}] is not registered.");
    }
}
