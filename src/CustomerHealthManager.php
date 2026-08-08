<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth;

use ByRcsc\LaravelCustomerHealth\Actions\RecordProductEvent;
use ByRcsc\LaravelCustomerHealth\Data\ProductEventData;
use ByRcsc\LaravelCustomerHealth\Events\ProductEvent;
use ByRcsc\LaravelCustomerHealth\Jobs\RecordProductEvent as RecordProductEventJob;
use ByRcsc\LaravelCustomerHealth\Registry\EventRegistry;
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

    private function configString(string $key): ?string
    {
        $value = $this->config->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
