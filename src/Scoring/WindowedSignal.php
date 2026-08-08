<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Scoring;

interface WindowedSignal extends Signal
{
    public function windowDays(): int;
}
