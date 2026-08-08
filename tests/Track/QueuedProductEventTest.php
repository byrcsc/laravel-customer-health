<?php

declare(strict_types=1);

use ByRcsc\LaravelCustomerHealth\Actions\RecordProductEvent as RecordProductEventAction;
use ByRcsc\LaravelCustomerHealth\Events\MilestoneReached;
use ByRcsc\LaravelCustomerHealth\Events\ProductEventRecorded;
use ByRcsc\LaravelCustomerHealth\Exceptions\InvalidEventPropertiesException;
use ByRcsc\LaravelCustomerHealth\Facades\CustomerHealth;
use ByRcsc\LaravelCustomerHealth\Jobs\RecordProductEvent;
use ByRcsc\LaravelCustomerHealth\Models\Milestone;
use ByRcsc\LaravelCustomerHealth\Models\ProductEventRecord;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\AccountCreated;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\TestActor;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\TestSubject;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\WorkflowCreated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

function queuedProductEvent(): RecordProductEvent
{
    Queue::assertPushed(RecordProductEvent::class, 1);
    $job = Queue::pushed(RecordProductEvent::class)->first();

    if (! $job instanceof RecordProductEvent) {
        throw new RuntimeException('The queued product event was not available.');
    }

    return $job;
}

function queuedPayloadContainsModel(mixed $value): bool
{
    if ($value instanceof Model) {
        return true;
    }

    if (! is_array($value) && ! is_object($value)) {
        return false;
    }

    foreach ((array) $value as $child) {
        if (queuedPayloadContainsModel($child)) {
            return true;
        }
    }

    return false;
}

beforeEach(function (): void {
    dropCustomerHealthStorage('testing');
    runCustomerHealthStorageMigrations();
    createTrackableFixtures();

    config()->set('customer-health.events', [WorkflowCreated::class, AccountCreated::class]);
    config()->set('customer-health.queue', true);
});

afterEach(function (): void {
    config()->set('database.default', 'testing');
    config()->set('customer-health.connection');
    dropCustomerHealthStorage('testing');

    if (config('database.connections.tenant') !== null) {
        dropCustomerHealthStorage('tenant');
    }
});

it('dispatches tracking to the configured queue without writing inline', function (): void {
    Queue::fake();
    config()->set('customer-health.queue_connection', 'redis');
    config()->set('customer-health.queue_name', 'customer-events');
    $subject = TestSubject::query()->create(['name' => 'Acme']);

    CustomerHealth::track(new WorkflowCreated($subject));

    expect(ProductEventRecord::query()->count())->toBe(0);

    Queue::assertPushed(RecordProductEvent::class, function (RecordProductEvent $job): bool {
        return $job->connection === 'redis' && $job->queue === 'customer-events';
    });
});

it('carries only primitive event data and survives serialization', function (): void {
    Queue::fake();
    $subject = TestSubject::query()->create(['name' => 'Acme']);
    $actor = TestActor::query()->create(['name' => 'Taylor']);

    CustomerHealth::track(new WorkflowCreated($subject, $actor, ['template' => 'approval']));

    /** @var RecordProductEvent $job */
    $job = unserialize(serialize(queuedProductEvent()));

    expect(queuedPayloadContainsModel($job))->toBeFalse()
        ->and($job->event->subjectId)->toBe((string) $subject->getKey())
        ->and($job->event->actorId)->toBe((string) $actor->getKey())
        ->and($job->event->properties)->toBe(['template' => 'approval']);
});

it('rejects nested non primitive properties before queueing', function (): void {
    Queue::fake();
    $subject = TestSubject::query()->create(['name' => 'Acme']);
    $nestedModel = TestSubject::query()->create(['name' => 'Nested']);

    CustomerHealth::track(new WorkflowCreated($subject, properties: [
        'context' => ['model' => $nestedModel],
    ]));
})->throws(InvalidEventPropertiesException::class);

it('produces the same rows and fired events as synchronous tracking', function (): void {
    Queue::fake();
    Event::fake([ProductEventRecorded::class, MilestoneReached::class]);
    $subject = TestSubject::query()->create(['name' => 'Acme']);

    config()->set('customer-health.queue', false);
    CustomerHealth::track(new WorkflowCreated($subject, properties: ['template' => 'approval']));
    $syncEvent = ProductEventRecord::query()->sole()->only([
        'subject_type', 'subject_id', 'actor_type', 'actor_id', 'name',
        'feature', 'properties',
    ]);
    $syncEventOccurredAt = ProductEventRecord::query()->sole()->occurred_at;
    $syncMilestone = Milestone::query()->sole()->only([
        'subject_type', 'subject_id', 'actor_type', 'actor_id', 'name',
    ]);
    $syncMilestoneOccurredAt = Milestone::query()->sole()->occurred_at;

    ProductEventRecord::query()->delete();
    Milestone::query()->delete();
    Event::fake([ProductEventRecorded::class, MilestoneReached::class]);
    config()->set('customer-health.queue', true);
    CustomerHealth::track(new WorkflowCreated($subject, properties: ['template' => 'approval']));
    queuedProductEvent()->handle(app(RecordProductEventAction::class));

    expect(ProductEventRecord::query()->sole()->only(array_keys($syncEvent)))->toBe($syncEvent)
        ->and(ProductEventRecord::query()->sole()->occurred_at->equalTo($syncEventOccurredAt))->toBeTrue()
        ->and(Milestone::query()->sole()->only(array_keys($syncMilestone)))->toBe($syncMilestone)
        ->and(Milestone::query()->sole()->occurred_at->equalTo($syncMilestoneOccurredAt))->toBeTrue();

    Event::assertDispatchedTimes(ProductEventRecorded::class, 1);
    Event::assertDispatchedTimes(MilestoneReached::class, 1);
});

it('preserves actors and non milestone behavior through the queue', function (): void {
    Queue::fake();
    Event::fake([ProductEventRecorded::class, MilestoneReached::class]);
    $subject = TestSubject::query()->create(['name' => 'Acme']);
    $actor = TestActor::query()->create(['name' => 'Taylor']);

    CustomerHealth::track(new AccountCreated($subject, $actor));
    queuedProductEvent()->handle(app(RecordProductEventAction::class));

    $record = ProductEventRecord::query()->sole();

    expect($record->actor?->is($actor))->toBeTrue()
        ->and($record->name)->toBe('account_opened')
        ->and(Milestone::query()->count())->toBe(0);

    Event::assertDispatchedTimes(ProductEventRecorded::class, 1);
    Event::assertNotDispatched(MilestoneReached::class);
});

it('writes on the worker active connection after serialization', function (): void {
    Queue::fake();
    $cleanup = configureCustomerHealthTenantDatabase();
    $subject = TestSubject::query()->create(['name' => 'Acme']);

    try {
        CustomerHealth::track(new WorkflowCreated($subject));
        $job = unserialize(serialize(queuedProductEvent()));

        config()->set('database.default', 'tenant');
        runCustomerHealthStorageMigrations();
        $job->handle(app(RecordProductEventAction::class));

        expect(ProductEventRecord::on('testing')->count())->toBe(0)
            ->and(Milestone::on('testing')->count())->toBe(0)
            ->and(ProductEventRecord::on('tenant')->count())->toBe(1)
            ->and(Milestone::on('tenant')->count())->toBe(1);
    } finally {
        config()->set('database.default', 'testing');
        $cleanup();
    }
});

it('writes queued events and milestones to the configured connection', function (): void {
    Queue::fake();
    $cleanup = configureCustomerHealthTenantDatabase();

    try {
        config()->set('customer-health.connection', 'tenant');
        runCustomerHealthStorageMigrations();
        createTrackableFixtures('tenant');
        $subject = TestSubject::on('tenant')->create(['name' => 'Tenant']);

        CustomerHealth::track(new WorkflowCreated($subject));
        queuedProductEvent()->handle(app(RecordProductEventAction::class));

        expect(ProductEventRecord::on('testing')->count())->toBe(0)
            ->and(Milestone::on('testing')->count())->toBe(0)
            ->and(ProductEventRecord::on('tenant')->count())->toBe(1)
            ->and(Milestone::on('tenant')->count())->toBe(1);
    } finally {
        config()->set('customer-health.connection');
        $cleanup();
    }
});

it('retries after a post commit failure without duplicating the milestone', function (): void {
    Queue::fake();
    $subject = TestSubject::query()->create(['name' => 'Acme']);
    $milestoneDispatches = 0;
    $failFirstAttempt = true;

    Event::listen(MilestoneReached::class, function () use (&$milestoneDispatches, &$failFirstAttempt): void {
        $milestoneDispatches++;

        if ($failFirstAttempt) {
            $failFirstAttempt = false;
            throw new RuntimeException('Listener failed after the write committed.');
        }
    });

    CustomerHealth::track(new WorkflowCreated($subject));
    $job = queuedProductEvent();

    expect(fn () => $job->handle(app(RecordProductEventAction::class)))
        ->toThrow(RuntimeException::class, 'Listener failed after the write committed.');
    $job->handle(app(RecordProductEventAction::class));

    expect(ProductEventRecord::query()->count())->toBe(2)
        ->and(Milestone::query()->count())->toBe(1)
        ->and($milestoneDispatches)->toBe(1);
});
