<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Events;

use ByRcsc\LaravelCustomerHealth\Models\HealthScoreRecord;
use ByRcsc\LaravelCustomerHealth\ValueObjects\ScoreResult;

final readonly class HealthScoreComputed
{
    public function __construct(public HealthScoreRecord $record, public ScoreResult $result) {}
}
