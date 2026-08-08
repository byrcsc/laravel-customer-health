<?php

declare(strict_types=1);

use ByRcsc\LaravelCustomerHealth\Facades\CustomerHealth;
use ByRcsc\LaravelCustomerHealth\Models\ProductEventRecord;
use ByRcsc\LaravelCustomerHealth\Support\TableNames;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\AccountCreated;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\TestActor;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\TestSubject;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\WorkflowCreated;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    dropCustomerHealthStorage('testing');
    runCustomerHealthStorageMigrations();
    createTrackableFixtures();

    config()->set('customer-health.events', [WorkflowCreated::class, AccountCreated::class]);
    config()->set('customer-health.queue', false);
    CarbonImmutable::setTestNow('2026-08-08 00:00:00 UTC');
});

afterEach(function (): void {
    dropCustomerHealthStorage('testing');
});

it('answers adoption from permanent milestones after raw events are deleted', function (): void {
    $subject = TestSubject::query()->create(['name' => 'Acme']);

    CustomerHealth::track(new WorkflowCreated($subject));

    expect(CustomerHealth::hasAdopted($subject, 'workflows'))->toBeTrue()
        ->and(CustomerHealth::hasAdopted($subject, 'accounts'))->toBeFalse();

    $subject->productEvents()->delete();

    expect(CustomerHealth::hasAdopted($subject, 'workflows'))->toBeTrue();
});

it('summarizes feature usage with inclusive UTC window boundaries', function (): void {
    $subject = TestSubject::query()->create(['name' => 'Acme']);
    $firstActor = TestActor::query()->create(['name' => 'Taylor']);
    $secondActor = TestActor::query()->create(['name' => 'Morgan']);

    CustomerHealth::track(new WorkflowCreated(
        $subject,
        $firstActor,
        occurredAt: CarbonImmutable::now('UTC')->subDays(30),
    ));
    CustomerHealth::track(new WorkflowCreated(
        $subject,
        $secondActor,
        occurredAt: CarbonImmutable::now('UTC')->subDays(10),
    ));
    CustomerHealth::track(new WorkflowCreated(
        $subject,
        actor: null,
        occurredAt: CarbonImmutable::now('UTC'),
    ));

    $usage = CustomerHealth::featureUsage('workflows')->for($subject);

    expect($usage->firstUsedAt?->equalTo(CarbonImmutable::now('UTC')->subDays(30)))->toBeTrue()
        ->and($usage->lastUsedAt?->equalTo(CarbonImmutable::now('UTC')))->toBeTrue()
        ->and($usage->eventCount())->toBe(3)
        ->and($usage->eventCount(days: 30))->toBe(3)
        ->and($usage->eventCount(days: 29))->toBe(2)
        ->and($usage->distinctActors())->toBe(2)
        ->and($usage->distinctActors(days: 30))->toBe(2)
        ->and($usage->distinctActors(days: 9))->toBe(0);
});

it('returns last activity and exposes model sugar', function (): void {
    $subject = TestSubject::query()->create(['name' => 'Acme']);
    $occurredAt = CarbonImmutable::now('UTC')->subHours(3);

    CustomerHealth::track(new AccountCreated($subject, occurredAt: $occurredAt));

    expect(CustomerHealth::lastSeen($subject)?->equalTo($occurredAt))->toBeTrue()
        ->and($subject->lastProductActivity()?->equalTo($occurredAt))->toBeTrue()
        ->and($subject->hasAdopted('accounts'))->toBeFalse();
});

it('finds subjects older than a UTC window including milestone only subjects', function (): void {
    $active = TestSubject::query()->create(['name' => 'Active']);
    $atBoundary = TestSubject::query()->create(['name' => 'Boundary']);
    $inactive = TestSubject::query()->create(['name' => 'Inactive']);
    $milestoneOnly = TestSubject::query()->create(['name' => 'Milestone only']);

    CustomerHealth::track(new AccountCreated($active, occurredAt: CarbonImmutable::now('UTC')->subDays(2)));
    CustomerHealth::track(new AccountCreated($atBoundary, occurredAt: CarbonImmutable::now('UTC')->subDays(14)));
    CustomerHealth::track(new WorkflowCreated($inactive, occurredAt: CarbonImmutable::now('UTC')->subDays(15)));
    CustomerHealth::track(new WorkflowCreated($milestoneOnly, occurredAt: CarbonImmutable::now('UTC')->subDays(30)));
    $milestoneOnly->productEvents()->delete();

    $references = CustomerHealth::inactive(days: 14)->get();

    expect($references->pluck('id')->all())->toEqualCanonicalizing([
        (string) $inactive->getKey(),
        (string) $milestoneOnly->getKey(),
    ])
        ->and($references->map->resolve()->filter()->pluck('name')->all())
        ->toEqualCanonicalizing(['Inactive', 'Milestone only']);
});

it('lists registered features and their event declarations', function (): void {
    expect(CustomerHealth::features())->toBe([
        'workflows' => [WorkflowCreated::class],
        'accounts' => [AccountCreated::class],
    ]);
});

it('runs adoption usage activity and inactivity queries on the package connection', function (): void {
    $cleanup = configureCustomerHealthTenantDatabase();

    try {
        config()->set('customer-health.connection', 'tenant');
        runCustomerHealthStorageMigrations();
        createTrackableFixtures('tenant');
        $subject = TestSubject::on('tenant')->create(['name' => 'Tenant']);

        CustomerHealth::track(new WorkflowCreated($subject));

        expect(CustomerHealth::hasAdopted($subject, 'workflows'))->toBeTrue()
            ->and(CustomerHealth::featureUsage('workflows')->for($subject)->eventCount())->toBe(1)
            ->and(CustomerHealth::lastSeen($subject))->not->toBeNull();

        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(15));
        $reference = CustomerHealth::inactive(14)->get()->sole();

        expect($reference->id)->toBe((string) $subject->getKey())
            ->and($reference->resolve('tenant')?->is($subject))->toBeTrue();
    } finally {
        config()->set('customer-health.connection');
        $cleanup();
    }
});

it('uses an index for subject feature window queries on mysql', function (): void {
    if (config('database.connections.testing.driver') !== 'mysql') {
        $this->markTestSkipped('Index selection is verified by the MySQL CI jobs.');
    }

    $occurredAt = CarbonImmutable::now('UTC')->toDateTimeString();
    $rows = [];

    for ($id = 1; $id <= 2_000; $id++) {
        $rows[] = [
            'subject_type' => TestSubject::class,
            'subject_id' => (string) $id,
            'actor_type' => null,
            'actor_id' => null,
            'name' => $id % 2 === 0 ? 'workflow_created' : 'account_opened',
            'feature' => $id % 2 === 0 ? 'workflows' : 'accounts',
            'properties' => '{}',
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
        ];
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table(TableNames::events())->insert($chunk);
    }

    $query = ProductEventRecord::query()
        ->where('subject_type', TestSubject::class)
        ->where('subject_id', '42')
        ->whereIn('name', ['workflow_created'])
        ->where('occurred_at', '>=', CarbonImmutable::now('UTC')->subDays(30));
    $plan = DB::select('EXPLAIN '.$query->toSql(), $query->getBindings())[0];

    expect($plan->type)->not->toBe('ALL')
        ->and($plan->key)->toBe('ch_events_subject_name_time_idx');
});
