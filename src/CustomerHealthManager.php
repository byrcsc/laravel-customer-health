<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth;

use ByRcsc\LaravelCustomerHealth\Actions\RecordProductEvent;
use ByRcsc\LaravelCustomerHealth\Contracts\Trackable;
use ByRcsc\LaravelCustomerHealth\Data\MorphIdentity;
use ByRcsc\LaravelCustomerHealth\Data\ProductEventData;
use ByRcsc\LaravelCustomerHealth\Events\ProductEvent;
use ByRcsc\LaravelCustomerHealth\Jobs\RecordProductEvent as RecordProductEventJob;
use ByRcsc\LaravelCustomerHealth\Models\Milestone;
use ByRcsc\LaravelCustomerHealth\Models\ProductEventRecord;
use ByRcsc\LaravelCustomerHealth\Queries\FeatureUsageQuery;
use ByRcsc\LaravelCustomerHealth\Queries\InactiveSubjectsQuery;
use ByRcsc\LaravelCustomerHealth\Registry\EventRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;

final readonly class CustomerHealthManager
{
    public function __construct(
        private EventRegistry $events,
        private RecordProductEvent $recordProductEvent,
        private Dispatcher $bus,
        private Repository $config,
    ) {}

    public function track(ProductEvent $event): void
    {
        $data = ProductEventData::from($event, $this->events);

        if ($this->config->get('customer-health.queue', false) === true) {
            $this->bus->dispatch(new RecordProductEventJob(
                event: $data,
                connection: $this->configString('customer-health.queue_connection'),
                queue: $this->configString('customer-health.queue_name'),
            ));

            return;
        }

        $this->recordProductEvent->handle($data);
    }

    /**
     * @return array<string, list<class-string<ProductEvent>>>
     */
    public function features(): array
    {
        return $this->events->features();
    }

    public function hasAdopted(Trackable $subject, string $feature): bool
    {
        $identity = MorphIdentity::from($subject, 'subject');
        $milestoneNames = collect($this->events->eventsForFeature($feature))
            ->filter(fn (string $event): bool => $event::$milestone)
            ->map(fn (string $event): string => $event::name())
            ->values()
            ->all();

        if ($milestoneNames === []) {
            return false;
        }

        return Milestone::query()
            ->where('subject_type', $identity->type)
            ->where('subject_id', $identity->id)
            ->whereIn('name', $milestoneNames)
            ->exists();
    }

    public function featureUsage(string $feature): FeatureUsageQuery
    {
        $eventNames = array_map(
            fn (string $event): string => $event::name(),
            $this->events->eventsForFeature($feature),
        );

        return new FeatureUsageQuery($eventNames);
    }

    public function lastSeen(Trackable $subject): ?CarbonImmutable
    {
        $identity = MorphIdentity::from($subject, 'subject');
        /** @var string|null $lastSeen */
        $lastSeen = ProductEventRecord::query()
            ->where('subject_type', $identity->type)
            ->where('subject_id', $identity->id)
            ->max('occurred_at');

        return $lastSeen === null ? null : CarbonImmutable::parse($lastSeen, 'UTC');
    }

    public function inactive(int $days): InactiveSubjectsQuery
    {
        return new InactiveSubjectsQuery($days);
    }

    private function configString(string $key): ?string
    {
        $value = $this->config->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
