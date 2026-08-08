<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Exceptions;

final class ScoreComputationLockException extends CustomerHealthException
{
    public static function timedOut(): self
    {
        return new self('Timed out waiting to compute this subject health score.');
    }
}
