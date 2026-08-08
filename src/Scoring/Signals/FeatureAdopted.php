<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Scoring\Signals;

use ByRcsc\LaravelCustomerHealth\Contracts\Trackable;
use ByRcsc\LaravelCustomerHealth\Facades\CustomerHealth;
use ByRcsc\LaravelCustomerHealth\Scoring\Signal;

final readonly class FeatureAdopted implements Signal
{
    public function __construct(public string $feature, public float $weight) {}

    public function evaluate(Trackable $subject): int
    {
        return CustomerHealth::hasAdopted($subject, $this->feature) ? 100 : 0;
    }

    public function weight(): float
    {
        return $this->weight;
    }
}
