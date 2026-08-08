<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth;

use ByRcsc\LaravelCustomerHealth\Events\MilestoneReached;
use ByRcsc\LaravelCustomerHealth\Events\ProductEvent;
use ByRcsc\LaravelCustomerHealth\Events\ProductEventRecorded;
use ByRcsc\LaravelCustomerHealth\Exceptions\InvalidTrackableException;
use ByRcsc\LaravelCustomerHealth\Models\Milestone;
use ByRcsc\LaravelCustomerHealth\Models\ProductEventRecord;
use ByRcsc\LaravelCustomerHealth\Registry\EventRegistry;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;

final readonly class CustomerHealthManager
{
    public function __construct(
        private EventRegistry $events,
        private Dispatcher $dispatcher,
    ) {}

    public function track(ProductEvent $event): void
    {
        $name = $this->events->nameFor($event::class);
        $subject = $this->modelFor($event->subject, 'subject');
        $actor = $event->actor === null ? null : $this->modelFor($event->actor, 'actor');
        $connection = (new ProductEventRecord)->getConnection();

        /** @var array{0: ProductEventRecord, 1: Milestone|null} $result */
        $result = $connection->transaction(function () use ($event, $name, $subject, $actor): array {
            $identity = $this->identityAttributes($subject, $actor);
            $record = ProductEventRecord::query()->create([
                ...$identity,
                'name' => $name,
                'feature' => $event::$feature,
                'properties' => $event->properties,
                'occurred_at' => $event->occurredAt,
            ]);

            $milestone = $event::$milestone
                ? $this->insertMilestone($event, $name, $subject, $actor)
                : null;

            return [$record, $milestone];
        });
        [$record, $milestone] = $result;

        $this->dispatcher->dispatch(new ProductEventRecorded($record));

        if ($milestone !== null) {
            $this->dispatcher->dispatch(new MilestoneReached($milestone));
        }
    }

    /**
     * @return array<string, list<class-string<ProductEvent>>>
     */
    public function features(): array
    {
        return $this->events->features();
    }

    private function insertMilestone(
        ProductEvent $event,
        string $name,
        Model $subject,
        ?Model $actor,
    ): ?Milestone {
        $attributes = [
            ...$this->identityAttributes($subject, $actor),
            'name' => $name,
            'occurred_at' => $event->occurredAt,
            'created_at' => now('UTC'),
        ];

        if (Milestone::query()->insertOrIgnore($attributes) !== 1) {
            return null;
        }

        return Milestone::query()
            ->where('subject_type', $attributes['subject_type'])
            ->where('subject_id', $attributes['subject_id'])
            ->where('name', $name)
            ->sole();
    }

    private function modelFor(object $value, string $role): Model
    {
        if (! $value instanceof Model) {
            throw InvalidTrackableException::notEloquent($role);
        }

        if ($value->getKey() === null) {
            throw InvalidTrackableException::notPersisted($role);
        }

        return $value;
    }

    private function morphTypeFor(Model $model): string
    {
        $morphType = $model->getMorphClass();

        if ($morphType === '') {
            throw InvalidTrackableException::invalidMorphType();
        }

        return $morphType;
    }

    private function keyFor(Model $model): string
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw InvalidTrackableException::invalidKey();
        }

        return (string) $key;
    }

    /**
     * @return array{subject_type: string, subject_id: string, actor_type: string|null, actor_id: string|null}
     */
    private function identityAttributes(Model $subject, ?Model $actor): array
    {
        return [
            'subject_type' => $this->morphTypeFor($subject),
            'subject_id' => $this->keyFor($subject),
            'actor_type' => $actor === null ? null : $this->morphTypeFor($actor),
            'actor_id' => $actor === null ? null : $this->keyFor($actor),
        ];
    }
}
