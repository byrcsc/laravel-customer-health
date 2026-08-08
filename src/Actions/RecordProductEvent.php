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
    public function __construct(
        private Dispatcher $dispatcher,
        private RecordOnboardingProgress $onboarding,
    ) {}

    public function handle(ProductEventData $event): void
    {
        $connection = (new ProductEventRecord)->getConnection();

        [$record, $milestone, $milestoneWasInserted] = $connection->transaction(function () use ($event): array {
            $occurredAt = CarbonImmutable::parse($event->occurredAt, 'UTC');
            $record = ProductEventRecord::query()->create([
                ...$event->identityAttributes(),
                'name' => $event->name,
                'feature' => $event->feature,
                'properties' => $event->properties,
                'occurred_at' => $occurredAt,
            ]);

            [$milestone, $milestoneWasInserted] = $event->milestone
                ? $this->insertMilestone($event, $occurredAt)
                : [null, false];

            return [$record, $milestone, $milestoneWasInserted];
        });

        if ($milestone !== null) {
            $this->onboarding->afterCommit($milestone, $milestoneWasInserted);
        }

        $this->dispatcher->dispatch(new ProductEventRecorded($record));

        if ($milestone !== null && $milestoneWasInserted) {
            $this->dispatcher->dispatch(new MilestoneReached($milestone));
        }
    }

    /** @return array{Milestone, bool} */
    private function insertMilestone(ProductEventData $event, CarbonImmutable $occurredAt): array
    {
        $attributes = [
            ...$event->identityAttributes(),
            'name' => $event->name,
            'occurred_at' => $occurredAt,
            'created_at' => now('UTC'),
        ];

        $inserted = Milestone::query()->insertOrIgnore($attributes) === 1;

        $milestone = Milestone::query()
            ->where('subject_type', $event->subjectType)
            ->where('subject_id', $event->subjectId)
            ->where('name', $event->name)
            ->sole();

        return [$milestone, $inserted];
    }
}
