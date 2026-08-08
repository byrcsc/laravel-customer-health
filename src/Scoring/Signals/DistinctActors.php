<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Scoring\Signals;

use ByRcsc\LaravelCustomerHealth\Contracts\Trackable;
use ByRcsc\LaravelCustomerHealth\Data\MorphIdentity;
use ByRcsc\LaravelCustomerHealth\Models\ProductEventRecord;
use ByRcsc\LaravelCustomerHealth\Scoring\Signal;
use Carbon\CarbonImmutable;

final readonly class DistinctActors implements Signal
{
    public function __construct(public int $days, public float $weight) {}

    public function evaluate(Trackable $subject): int
    {
        $identity = MorphIdentity::from($subject, 'subject');

        return ProductEventRecord::query()
            ->where('subject_type', $identity->type)
            ->where('subject_id', $identity->id)
            ->where('occurred_at', '>=', CarbonImmutable::now('UTC')->subDays($this->days))
            ->whereNotNull('actor_type')
            ->whereNotNull('actor_id')
            ->exists() ? 100 : 0;
    }

    public function weight(): float
    {
        return $this->weight;
    }
}
