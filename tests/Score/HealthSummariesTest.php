<?php

declare(strict_types=1);

use ByRcsc\LaravelCustomerHealth\Contracts\TenantResolver;
use ByRcsc\LaravelCustomerHealth\Contracts\Trackable;
use ByRcsc\LaravelCustomerHealth\Events\HealthScoreComputed;
use ByRcsc\LaravelCustomerHealth\Events\HealthStateChanged;
use ByRcsc\LaravelCustomerHealth\Facades\CustomerHealth;
use ByRcsc\LaravelCustomerHealth\Models\HealthScoreRecord;
use ByRcsc\LaravelCustomerHealth\Models\HealthSummary;
use ByRcsc\LaravelCustomerHealth\Scoring\HealthScore;
use ByRcsc\LaravelCustomerHealth\Scoring\Signal;
use ByRcsc\LaravelCustomerHealth\Support\TableNames;
use ByRcsc\LaravelCustomerHealth\Tenancy\NullTenantResolver;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\AccountCreated;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\TestSubject;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    dropCustomerHealthStorage('testing');
    runCustomerHealthStorageMigrations();
    createTrackableFixtures();
    config()->set('customer-health.events', [AccountCreated::class]);
    config()->set('customer-health.checklists', []);
    config()->set('customer-health.scores', [SummaryHealthScore::class]);
    CarbonImmutable::setTestNow('2026-08-08 00:00:00 UTC');
});

afterEach(function (): void {
    SummarySignal::$value = 20;
    config()->set('customer-health.connection');
    config()->set('customer-health.summary_connection');
    config()->set('customer-health.tenant_resolver', NullTenantResolver::class);
    dropCustomerHealthStorage('testing');

    if (config('database.connections.landlord') !== null) {
        dropCustomerHealthStorage('landlord');
        DB::purge('landlord');
        config()->set('database.connections.landlord');
    }
});

it('upserts one current null tenant summary on the default connection', function (): void {
    $subject = TestSubject::query()->create(['name' => 'Acme']);

    CustomerHealth::compute($subject);
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDay());
    SummarySignal::$value = 80;
    CustomerHealth::compute($subject);

    $summary = HealthSummary::query()->sole();
    expect($summary->tenant_id)->toBeNull()
        ->and($summary->value)->toBe(80)
        ->and($summary->state)->toBe('healthy')
        ->and($summary->subjectIdentity()->resolve()?->is($subject))->toBeTrue()
        ->and(HealthScoreRecord::query()->count())->toBe(2);
});

it('writes history and tenant summary across separate connections', function (): void {
    configureLandlordSummaryDatabase();
    config()->set('customer-health.summary_connection', 'landlord');
    config()->set('customer-health.tenant_resolver', FixedTenantResolver::class);
    runCustomerHealthSummaryMigration();
    $subject = TestSubject::query()->create(['name' => 'Acme']);

    CustomerHealth::compute($subject);

    expect(HealthScoreRecord::on('testing')->count())->toBe(1)
        ->and(HealthSummary::on('landlord')->count())->toBe(1)
        ->and(HealthSummary::on('landlord')->sole()->tenant_id)->toBe('tenant-42')
        ->and(HealthSummary::on('testing')->count())->toBe(0);
});

it('queries current state from summaries alone', function (): void {
    $healthy = TestSubject::query()->create(['name' => 'Healthy']);
    $risk = TestSubject::query()->create(['name' => 'Risk']);
    SummarySignal::$value = 80;
    CustomerHealth::compute($healthy);
    SummarySignal::$value = 20;
    CustomerHealth::compute($risk);
    Schema::drop(TableNames::scores());

    $row = CustomerHealth::inState('at_risk')->get()->sole();
    expect($row->subject_id)->toBe((string) $risk->getKey())
        ->and(CustomerHealth::summaries()->count())->toBe(2);
});

it('fully rebuilds truncated summaries through recompute', function (): void {
    $first = TestSubject::query()->create(['name' => 'First']);
    $second = TestSubject::query()->create(['name' => 'Second']);
    CustomerHealth::track(new AccountCreated($first));
    CustomerHealth::track(new AccountCreated($second));

    expect(Artisan::call('customer-health:recompute'))->toBe(Command::SUCCESS)
        ->and(HealthSummary::query()->count())->toBe(2);
    HealthSummary::query()->truncate();
    expect(Artisan::call('customer-health:recompute'))->toBe(Command::SUCCESS)
        ->and(HealthSummary::query()->count())->toBe(2);
});

it('rolls back history and events when summary synchronization fails', function (): void {
    Event::fake([HealthScoreComputed::class, HealthStateChanged::class]);
    $subject = TestSubject::query()->create(['name' => 'Acme']);
    Schema::drop(TableNames::summaries());

    expect(fn () => CustomerHealth::compute($subject))->toThrow(QueryException::class)
        ->and(HealthScoreRecord::query()->count())->toBe(0);
    Event::assertNotDispatched(HealthScoreComputed::class);
    Event::assertNotDispatched(HealthStateChanged::class);
});

final class SummaryHealthScore extends HealthScore
{
    public function signals(): array
    {
        return [new SummarySignal(1)];
    }

    public function states(): array
    {
        return ['at_risk' => 0, 'healthy' => 75];
    }
}

final class SummarySignal implements Signal
{
    public static int $value = 20;

    public function __construct(private readonly float $signalWeight) {}

    public function evaluate(Trackable $subject): int
    {
        return self::$value;
    }

    public function weight(): float
    {
        return $this->signalWeight;
    }
}

final class FixedTenantResolver implements TenantResolver
{
    public function __invoke(): string
    {
        return 'tenant-42';
    }
}

function configureLandlordSummaryDatabase(): void
{
    config()->set('database.connections.landlord', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
}
