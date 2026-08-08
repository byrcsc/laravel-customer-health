<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Exceptions;

final class InvalidScoreDefinitionException extends CustomerHealthException
{
    public static function invalid(string $message): self
    {
        return new self($message);
    }
}
