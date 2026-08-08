<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Scoring\Signals;

use ByRcsc\LaravelCustomerHealth\Contracts\Trackable;
use ByRcsc\LaravelCustomerHealth\Facades\CustomerHealth;
use ByRcsc\LaravelCustomerHealth\Scoring\WindowedSignal;
use Carbon\CarbonImmutable;

final readonly class RecentActivity implements WindowedSignal
{
    public function __construct(public int $days, public float $weight) {}

    public function evaluate(Trackable $subject): int
    {
        $lastSeen = CustomerHealth::lastSeen($subject);

        return $lastSeen !== null && $lastSeen->greaterThanOrEqualTo(CarbonImmutable::now('UTC')->subDays($this->days))
            ? 100
            : 0;
    }

    public function weight(): float
    {
        return $this->weight;
    }

    public function windowDays(): int
    {
        return $this->days;
    }
}
