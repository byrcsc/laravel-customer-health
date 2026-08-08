<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Jobs;

use ByRcsc\LaravelCustomerHealth\Actions\RecordProductEvent as RecordProductEventAction;
use ByRcsc\LaravelCustomerHealth\Data\ProductEventData;
use Illuminate\Contracts\Queue\ShouldQueue;

final class RecordProductEvent implements ShouldQueue
{
    public ?string $connection;

    public ?string $queue;

    public function __construct(
        public readonly ProductEventData $event,
        ?string $connection = null,
        ?string $queue = null,
    ) {
        $this->connection = $connection;
        $this->queue = $queue;
    }

    public function handle(RecordProductEventAction $record): void
    {
        $record->handle($this->event);
    }
}
