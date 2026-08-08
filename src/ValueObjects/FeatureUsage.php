<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\ValueObjects;

use ByRcsc\LaravelCustomerHealth\Data\MorphIdentity;
use ByRcsc\LaravelCustomerHealth\Models\ProductEventRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final readonly class FeatureUsage
{
    public function __construct(
        /** @var list<string> */
        private array $eventNames,
        private MorphIdentity $subject,
        public ?CarbonImmutable $firstUsedAt,
        public ?CarbonImmutable $lastUsedAt,
    ) {}

    public function eventCount(?int $days = null): int
    {
        return $this->events($days)->count();
    }

    public function distinctActors(?int $days = null): int
    {
        $actors = $this->events($days)
            ->whereNotNull('actor_type')
            ->whereNotNull('actor_id')
            ->select(['actor_type', 'actor_id'])
            ->distinct();

        return (new ProductEventRecord)->getConnection()
            ->query()
            ->fromSub($actors->toBase(), 'distinct_actors')
            ->count();
    }

    /** @return Builder<ProductEventRecord> */
    private function events(?int $days): Builder
    {
        $query = ProductEventRecord::query()
            ->where('subject_type', $this->subject->type)
            ->where('subject_id', $this->subject->id)
            ->whereIn('name', $this->eventNames);

        if ($days !== null) {
            $query->where('occurred_at', '>=', CarbonImmutable::now('UTC')->subDays($days));
        }

        return $query;
    }
}
