<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Exceptions;

final class InvalidTenantResolverException extends CustomerHealthException
{
    public static function invalid(): self
    {
        return new self('The configured customer health tenant resolver must be an invokable class returning an integer, string, or null.');
    }
}
