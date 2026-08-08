<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Events;

use ByRcsc\LaravelCustomerHealth\Models\HealthScoreRecord;

final readonly class HealthStateChanged
{
    public function __construct(
        public HealthScoreRecord $record,
        public ?string $from,
        public string $to,
    ) {}
}
