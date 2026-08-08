<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Exceptions;

final class InvalidTrackableException extends CustomerHealthException
{
    public static function notEloquent(string $role): self
    {
        return new self("A customer health {$role} must be an Eloquent model.");
    }

    public static function notPersisted(string $role): self
    {
        return new self("A customer health {$role} must be persisted before tracking.");
    }

    public static function invalidMorphType(): self
    {
        return new self('A tracked model must have a non-empty morph type.');
    }

    public static function invalidKey(): self
    {
        return new self('A tracked model key must be an integer or string.');
    }
}
