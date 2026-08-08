<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Events;

use ByRcsc\LaravelCustomerHealth\Models\Milestone;

final readonly class MilestoneReached
{
    public function __construct(public Milestone $milestone) {}
}
