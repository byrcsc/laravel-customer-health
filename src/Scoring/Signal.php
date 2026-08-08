<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Scoring;

use ByRcsc\LaravelCustomerHealth\Contracts\Trackable;

interface Signal
{
    public function evaluate(Trackable $subject): int;

    public function weight(): float;
}
