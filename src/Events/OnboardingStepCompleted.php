<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Events;

use ByRcsc\LaravelCustomerHealth\Models\Milestone;

final readonly class OnboardingStepCompleted
{
    /** @param class-string<ProductEvent> $step */
    public function __construct(
        public Milestone $milestone,
        public string $checklist,
        public string $step,
    ) {}
}
