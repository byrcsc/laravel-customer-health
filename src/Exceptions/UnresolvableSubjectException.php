<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Exceptions;

final class UnresolvableSubjectException extends CustomerHealthException
{
    public static function forIdentity(string $type, string $id): self
    {
        return new self("Known subject [{$type}:{$id}] no longer resolves to a Trackable model.");
    }
}
