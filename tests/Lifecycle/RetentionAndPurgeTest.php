<?php

declare(strict_types=1);

use ByRcsc\LaravelCustomerHealth\Contracts\TenantResolver;
use ByRcsc\LaravelCustomerHealth\Facades\CustomerHealth;
use ByRcsc\LaravelCustomerHealth\Models\HealthScoreRecord;
use ByRcsc\LaravelCustomerHealth\Models\HealthSummary;
use ByRcsc\LaravelCustomerHealth\Models\Milestone;
use ByRcsc\LaravelCustomerHealth\Models\ProductEventRecord;
use ByRcsc\LaravelCustomerHealth\Scoring\HealthScore;
use ByRcsc\LaravelCustomerHealth\Scoring\Signals\DistinctActors;
use ByRcsc\LaravelCustomerHealth\Scoring\Signals\FeatureActivity;
use ByRcsc\LaravelCustomerHealth\Scoring\Signals\FeatureAdopted;
use ByRcsc\LaravelCustomerHealth\Scoring\Signals\RecentActivity;
use ByRcsc\LaravelCustomerHealth\Scoring\WindowedSignal;
use ByRcsc\LaravelCustomerHealth\Tenancy\NullTenantResolver;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\TeammateInvited;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\TestOnboarding;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\TestSubject;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\WorkflowCreated;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    dropCustomerHealthStorage('testing');
    config()->set('customer-health.connection');
    config()->set('customer-health.summary_connection');
    config()->set('customer-health.events', [WorkflowCreated::class, TeammateInvited::class]);
    config()->set('customer-health.checklists', [TestOnboarding::class]);
    config()->set('customer-health.scores', [RetentionHealthScore::class]);
    config()->set('customer-health.tenant_resolver', NullTenantResolver::class);
    config()->set('customer-health.retention_days');
    runCustomerHealthStorageMigrations();
    createTrackableFixtures();
    CarbonImmutable::setTestNow('2026-08-08 00:00:00 UTC');
});

afterEach(function (): void {
    config()->set('customer-health.connection');
    config()->set('customer-health.summary_connection');
    config()->set('customer-health.retention_days');
    config()->set('customer-health.tenant_resolver', NullTenantResolver::class);
    dropCustomerHealthStorage('testing');

    if (config('database.connections.tenant') !== null) {
        dropCustomerHealthStorage('tenant');
    }
});

it('prunes only expired raw events through the standard model command', function (): void {
    $subject = TestSubject::query()->create(['name' => 'Acme']);
    CustomerHealth::track(new WorkflowCreated(
        $subject,
        occurredAt: CarbonImmutable::now('UTC')->subDays(10),
    ));
    CustomerHealth::track(new TeammateInvited(
        $subject,
        occurredAt: CarbonImmutable::now('UTC')->subDays(9),
    ));
    CustomerHealth::compute($subject);
    $before = CustomerHealth::scoreHistory($subject)->all();
    config()->set('customer-health.retention_days', 0);

    expect(Artisan::call('model:prune', [
        '--model' => [ProductEventRecord::class],
    ]))->toBe(Command::SUCCESS)
        ->and(ProductEventRecord::query()->count())->toBe(0)
        ->and(Milestone::query()->count())->toBe(3)
        ->and(HealthScoreRecord::query()->count())->toBe(1)
        ->and(HealthSummary::query()->count())->toBe(1)
        ->and(CustomerHealth::hasAdopted($subject, 'workflows'))->toBeTrue()
        ->and(CustomerHealth::onboarding($subject)->isComplete())->toBeTrue()
        ->and(CustomerHealth::scoreHistory($subject)->all())->toEqual($before);
});

it('keeps raw events forever when retention is null', function (): void {
    $subject = TestSubject::query()->create(['name' => 'Acme']);
    CustomerHealth::track(new WorkflowCreated(
        $subject,
        occurredAt: CarbonImmutable::now('UTC')->subYears(10),
    ));

    expect(Artisan::call('model:prune', [
        '--model' => [ProductEventRecord::class],
    ]))->toBe(Command::SUCCESS)
        ->and(ProductEventRecord::query()->count())->toBe(1);
});

it('keeps events at the exact retention and signal window boundary', function (): void {
    $subject = TestSubject::query()->create(['name' => 'Acme']);
    $boundary = CarbonImmutable::now('UTC')->subDays(30);
    CustomerHealth::track(new WorkflowCreated($subject, occurredAt: $boundary->subSecond()));
    CustomerHealth::track(new TeammateInvited($subject, occurredAt: $boundary));
    config()->set('customer-health.retention_days', 30);

    expect(Artisan::call('model:prune', [
        '--model' => [ProductEventRecord::class],
    ]))->toBe(Command::SUCCESS)
        ->and(ProductEventRecord::query()->count())->toBe(1)
        ->and(ProductEventRecord::query()->sole()->occurred_at->equalTo($boundary))->toBeTrue();
});

it('warns when retention is shorter than a registered score window', function (): void {
    config()->set('customer-health.retention_days', 7);

    expect(Artisan::call('customer-health:recompute'))->toBe(Command::SUCCESS)
        ->and(Artisan::output())->toContain(
            'retention [7 days] is shorter than the longest registered signal window [30 days]',
        );
});

it('exposes retention windows on every built in activity signal', function (): void {
    $signals = [
        new RecentActivity(10, 1),
        new FeatureActivity('workflows', 20, 1),
        new DistinctActors(30, 1),
    ];

    expect($signals)->each->toBeInstanceOf(WindowedSignal::class)
        ->and(array_map(fn (WindowedSignal $signal): int => $signal->windowDays(), $signals))
        ->toBe([10, 20, 30]);
});

it('purges all subject data across tenant and landlord connections', function (): void {
    dropCustomerHealthStorage('testing');
    $cleanup = configureCustomerHealthTenantDatabase();

    try {
        config()->set('customer-health.connection', 'tenant');
        config()->set('customer-health.summary_connection', 'testing');
        config()->set('customer-health.tenant_resolver', ErasureTenantResolver::class);
        runCustomerHealthStorageMigrations();
        createTrackableFixtures('tenant');
        $subject = TestSubject::on('tenant')->create(['name' => 'Acme']);
        CustomerHealth::track(new WorkflowCreated($subject));
        CustomerHealth::compute($subject);

        expect(ProductEventRecord::on('tenant')->count())->toBe(1)
            ->and(Milestone::on('tenant')->count())->toBe(1)
            ->and(HealthScoreRecord::on('tenant')->count())->toBe(1)
            ->and(HealthSummary::on('testing')->count())->toBe(1);

        CustomerHealth::purge($subject);
        CustomerHealth::purge($subject);

        expect(ProductEventRecord::on('tenant')->count())->toBe(0)
            ->and(Milestone::on('tenant')->count())->toBe(0)
            ->and(HealthScoreRecord::on('tenant')->count())->toBe(0)
            ->and(HealthSummary::on('testing')->count())->toBe(0);
    } finally {
        $cleanup();
    }
});

it('purges by command with an explicit summary tenant id', function (): void {
    config()->set('customer-health.tenant_resolver', ErasureTenantResolver::class);
    $subject = TestSubject::query()->create(['name' => 'Acme']);
    $keeper = TestSubject::query()->create(['name' => 'Keep']);
    CustomerHealth::track(new WorkflowCreated($subject));
    CustomerHealth::compute($subject);
    CustomerHealth::track(new WorkflowCreated($keeper));
    CustomerHealth::compute($keeper);
    $targetSummary = HealthSummary::query()
        ->where('subject_id', (string) $subject->getKey())
        ->sole();
    HealthSummary::query()->create([
        'summary_key' => str_repeat('f', 64),
        'tenant_id' => 'tenant-keep',
        'subject_type' => $targetSummary->subject_type,
        'subject_id' => $targetSummary->subject_id,
        'score' => $targetSummary->score,
        'value' => $targetSummary->value,
        'state' => $targetSummary->state,
        'computed_at' => $targetSummary->computed_at,
    ]);
    config()->set('customer-health.tenant_resolver', NullTenantResolver::class);

    expect(Artisan::call('customer-health:purge', [
        'subject_type' => TestSubject::class,
        'subject_id' => (string) $subject->getKey(),
        '--tenant' => 'tenant-erase',
    ]))->toBe(Command::SUCCESS)
        ->and(ProductEventRecord::query()->count())->toBe(1)
        ->and(Milestone::query()->count())->toBe(1)
        ->and(HealthScoreRecord::query()->count())->toBe(1)
        ->and(HealthSummary::query()->count())->toBe(2)
        ->and(ProductEventRecord::query()->sole()->subject_id)->toBe((string) $keeper->getKey())
        ->and(HealthSummary::query()->where('tenant_id', 'tenant-keep')->sole()->subject_id)
        ->toBe((string) $subject->getKey());
});

final class RetentionHealthScore extends HealthScore
{
    public function signals(): array
    {
        return [
            new FeatureAdopted('workflows', 1),
            new RecentActivity(30, 1),
        ];
    }

    public function states(): array
    {
        return ['at_risk' => 0, 'healthy' => 75];
    }
}

final readonly class ErasureTenantResolver implements TenantResolver
{
    public function __invoke(): string
    {
        return 'tenant-erase';
    }
}
