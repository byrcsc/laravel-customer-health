<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Queries;

use ByRcsc\LaravelCustomerHealth\Contracts\Trackable;
use ByRcsc\LaravelCustomerHealth\Data\MorphIdentity;
use ByRcsc\LaravelCustomerHealth\Models\ProductEventRecord;
use ByRcsc\LaravelCustomerHealth\ValueObjects\FeatureUsage;
use Carbon\CarbonImmutable;

final readonly class FeatureUsageQuery
{
    /**
     * @param  list<string>  $eventNames
     */
    public function __construct(
        private array $eventNames,
    ) {}

    public function for(Trackable $subject): FeatureUsage
    {
        $identity = MorphIdentity::from($subject, 'subject');
        $query = ProductEventRecord::query()
            ->where('subject_type', $identity->type)
            ->where('subject_id', $identity->id)
            ->whereIn('name', $this->eventNames)
            ->toBase()
            ->selectRaw('MIN(occurred_at) AS first_used_at, MAX(occurred_at) AS last_used_at')
            ->first();
        $first = $this->dateProperty($query, 'first_used_at');
        $last = $this->dateProperty($query, 'last_used_at');

        return new FeatureUsage(
            eventNames: $this->eventNames,
            subject: $identity,
            firstUsedAt: $first === null ? null : CarbonImmutable::parse($first, 'UTC'),
            lastUsedAt: $last === null ? null : CarbonImmutable::parse($last, 'UTC'),
        );
    }

    private function dateProperty(?object $row, string $property): ?string
    {
        $value = $row?->{$property};

        return is_string($value) ? $value : null;
    }
}
