<?php

declare(strict_types=1);

use ByRcsc\LaravelCustomerHealth\Events\MilestoneReached;
use ByRcsc\LaravelCustomerHealth\Events\ProductEventRecorded;
use ByRcsc\LaravelCustomerHealth\Facades\CustomerHealth;
use ByRcsc\LaravelCustomerHealth\Models\Milestone;
use ByRcsc\LaravelCustomerHealth\Models\ProductEventRecord;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\AccountCreated;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\TestActor;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\TestSubject;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\WorkflowCreated;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    dropCustomerHealthStorage('testing');
    runCustomerHealthStorageMigrations();
    createTrackableFixtures();

    config()->set('customer-health.events', [
        WorkflowCreated::class,
        AccountCreated::class,
    ]);
});

afterEach(function (): void {
    config()->set('database.default', 'testing');
    config()->set('customer-health.connection');
    dropCustomerHealthStorage('testing');

    if (config('database.connections.tenant') !== null) {
        dropCustomerHealthStorage('tenant');
    }
});

it('records a product event with an actor and reaches a milestone once', function (): void {
    Event::fake([ProductEventRecorded::class, MilestoneReached::class]);
    CarbonImmutable::setTestNow('2026-08-08 10:00:00 Australia/Brisbane');

    $subject = TestSubject::query()->create(['name' => 'Acme']);
    $actor = TestActor::query()->create(['name' => 'Taylor']);

    CustomerHealth::track(new WorkflowCreated(
        subject: $subject,
        actor: $actor,
        properties: ['template' => 'approval'],
    ));

    $record = ProductEventRecord::query()->sole();
    $milestone = Milestone::query()->sole();

    expect($record->subject->is($subject))->toBeTrue()
        ->and($record->actor?->is($actor))->toBeTrue()
        ->and($record->name)->toBe('workflow_created')
        ->and($record->feature)->toBe('workflows')
        ->and($record->properties)->toBe(['template' => 'approval'])
        ->and($record->occurred_at->format('Y-m-d H:i:s e'))->toBe('2026-08-08 00:00:00 UTC')
        ->and($milestone->name)->toBe('workflow_created');

    Event::assertDispatched(ProductEventRecorded::class, fn (ProductEventRecorded $event): bool => $event->record->is($record));
    Event::assertDispatched(MilestoneReached::class, fn (MilestoneReached $event): bool => $event->milestone->is($milestone));
});

it('records repeated and non milestone events without duplicate milestones', function (): void {
    Event::fake([ProductEventRecorded::class, MilestoneReached::class]);

    $subject = TestSubject::query()->create(['name' => 'Acme']);

    CustomerHealth::track(new WorkflowCreated($subject));
    CustomerHealth::track(new WorkflowCreated($subject));
    CustomerHealth::track(new AccountCreated($subject));

    expect(ProductEventRecord::query()->count())->toBe(3)
        ->and(Milestone::query()->count())->toBe(1);

    Event::assertDispatchedTimes(ProductEventRecorded::class, 3);
    Event::assertDispatchedTimes(MilestoneReached::class, 1);
});

it('keeps first occurrences after raw events are deleted', function (): void {
    $subject = TestSubject::query()->create(['name' => 'Acme']);

    CustomerHealth::track(new WorkflowCreated($subject));
    ProductEventRecord::query()->delete();

    expect(ProductEventRecord::query()->count())->toBe(0)
        ->and($subject->milestones()->sole()->name)
        ->toBe('workflow_created');
});

it('records one first milestone and dispatch under concurrent tracking', function (): void {
    if (config('database.connections.testing.driver') === 'sqlite') {
        $this->markTestSkipped('The database race test runs on the MySQL and PostgreSQL CI jobs.');
    }

    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('The database race test requires pcntl.');
    }

    Schema::create('milestone_dispatches', function (Blueprint $table): void {
        $table->id();
        $table->string('event');
    });

    Event::listen(MilestoneReached::class, function (): void {
        DB::connection('testing')->table('milestone_dispatches')->insert(['event' => 'reached']);
    });

    $subject = TestSubject::query()->create(['name' => 'Acme']);
    $children = [];

    for ($index = 0; $index < 2; $index++) {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw new RuntimeException('Unable to create the concurrency barrier.');
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork the concurrency worker.');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            fread($sockets[1], 1);
            fclose($sockets[1]);

            try {
                DB::disconnect('testing');
                DB::reconnect('testing');
                $workerSubject = TestSubject::on('testing')->findOrFail($subject->getKey());
                CustomerHealth::track(new WorkflowCreated($workerSubject));
                exit(0);
            } catch (Throwable) {
                exit(1);
            }
        }

        fclose($sockets[1]);
        $children[] = [$pid, $sockets[0]];
    }

    foreach ($children as [, $socket]) {
        fwrite($socket, '1');
        fclose($socket);
    }

    foreach ($children as [$pid]) {
        pcntl_waitpid($pid, $status);
        expect(pcntl_wexitstatus($status))->toBe(0);
    }

    DB::disconnect('testing');
    DB::reconnect('testing');

    expect(ProductEventRecord::query()->count())->toBe(2)
        ->and(Milestone::query()->count())->toBe(1)
        ->and(DB::table('milestone_dispatches')->count())->toBe(1);
});

it('records system events with no actor', function (): void {
    $subject = TestSubject::query()->create(['name' => 'Acme']);

    CustomerHealth::track(new AccountCreated($subject));

    expect(ProductEventRecord::query()->sole())
        ->actor_type->toBeNull()
        ->actor_id->toBeNull();
});

it('follows the active default connection between track calls', function (): void {
    $cleanup = configureCustomerHealthTenantDatabase();

    try {
        $subject = TestSubject::query()->create(['name' => 'Default']);
        CustomerHealth::track(new WorkflowCreated($subject));

        config()->set('database.default', 'tenant');
        runCustomerHealthStorageMigrations();
        createTrackableFixtures('tenant');
        $tenantSubject = TestSubject::on('tenant')->create(['name' => 'Tenant']);
        CustomerHealth::track(new WorkflowCreated($tenantSubject));

        expect(ProductEventRecord::on('testing')->count())->toBe(1)
            ->and(Milestone::on('testing')->count())->toBe(1)
            ->and(ProductEventRecord::on('tenant')->count())->toBe(1)
            ->and(Milestone::on('tenant')->count())->toBe(1);
    } finally {
        config()->set('database.default', 'testing');
        $cleanup();
    }
});

it('writes the event and milestone transaction to the configured connection', function (): void {
    $cleanup = configureCustomerHealthTenantDatabase();

    try {
        config()->set('customer-health.connection', 'tenant');
        runCustomerHealthStorageMigrations();
        createTrackableFixtures('tenant');
        $subject = TestSubject::on('tenant')->create(['name' => 'Tenant']);

        CustomerHealth::track(new WorkflowCreated($subject));

        expect(ProductEventRecord::on('testing')->count())->toBe(0)
            ->and(Milestone::on('testing')->count())->toBe(0)
            ->and(ProductEventRecord::on('tenant')->count())->toBe(1)
            ->and(Milestone::on('tenant')->count())->toBe(1);
    } finally {
        config()->set('customer-health.connection');
        $cleanup();
    }
});
