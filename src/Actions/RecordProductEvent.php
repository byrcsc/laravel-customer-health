<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Actions;

use ByRcsc\LaravelCustomerHealth\Data\ProductEventData;
use ByRcsc\LaravelCustomerHealth\Events\MilestoneReached;
use ByRcsc\LaravelCustomerHealth\Events\ProductEventRecorded;
use ByRcsc\LaravelCustomerHealth\Models\Milestone;
use ByRcsc\LaravelCustomerHealth\Models\ProductEventRecord;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class RecordProductEvent
{
    public function __construct(private Dispatcher $dispatcher) {}

    public function handle(ProductEventData $event): void
    {
        $connection = (new ProductEventRecord)->getConnection();

        [$record, $milestone] = $connection->transaction(function () use ($event): array {
            $occurredAt = CarbonImmutable::parse($event->occurredAt, 'UTC');
            $record = ProductEventRecord::query()->create([
                ...$event->identityAttributes(),
                'name' => $event->name,
                'feature' => $event->feature,
                'properties' => $event->properties,
                'occurred_at' => $occurredAt,
            ]);

            $milestone = $event->milestone
                ? $this->insertMilestone($event, $occurredAt)
                : null;

            return [$record, $milestone];
        });

        $this->dispatcher->dispatch(new ProductEventRecorded($record));

        if ($milestone !== null) {
            $this->dispatcher->dispatch(new MilestoneReached($milestone));
        }
    }

    private function insertMilestone(ProductEventData $event, CarbonImmutable $occurredAt): ?Milestone
    {
        $attributes = [
            ...$event->identityAttributes(),
            'name' => $event->name,
            'occurred_at' => $occurredAt,
            'created_at' => now('UTC'),
        ];

        if (Milestone::query()->insertOrIgnore($attributes) !== 1) {
            return null;
        }

        return Milestone::query()
            ->where('subject_type', $event->subjectType)
            ->where('subject_id', $event->subjectId)
            ->where('name', $event->name)
            ->sole();
    }
}
