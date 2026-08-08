<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Scoring\Signals;

use ByRcsc\LaravelCustomerHealth\Contracts\Trackable;
use ByRcsc\LaravelCustomerHealth\Facades\CustomerHealth;
use ByRcsc\LaravelCustomerHealth\Onboarding\Checklist;
use ByRcsc\LaravelCustomerHealth\Scoring\Signal;

final readonly class OnboardingProgress implements Signal
{
    /** @param class-string<Checklist>|string $checklist */
    public function __construct(public string $checklist, public float $weight) {}

    public function evaluate(Trackable $subject): int
    {
        return CustomerHealth::onboarding($subject, $this->checklist)->percent();
    }

    public function weight(): float
    {
        return $this->weight;
    }
}
