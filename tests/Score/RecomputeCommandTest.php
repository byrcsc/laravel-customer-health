<?php

declare(strict_types=1);

use ByRcsc\LaravelCustomerHealth\Contracts\Trackable;
use ByRcsc\LaravelCustomerHealth\Events\HealthStateChanged;
use ByRcsc\LaravelCustomerHealth\Facades\CustomerHealth;
use ByRcsc\LaravelCustomerHealth\Models\HealthScoreRecord;
use ByRcsc\LaravelCustomerHealth\Models\Milestone;
use ByRcsc\LaravelCustomerHealth\Scoring\HealthScore;
use ByRcsc\LaravelCustomerHealth\Scoring\Signal;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\AccountCreated;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\TestSubject;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\WorkflowCreated;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    dropCustomerHealthStorage('testing');
    runCustomerHealthStorageMigrations();
    createTrackableFixtures();
    config()->set('customer-health.events', [AccountCreated::class, WorkflowCreated::class]);
    config()->set('customer-health.checklists', []);
    config()->set('customer-health.scores', [RecomputePrimaryScore::class, RecomputeSecondaryScore::class]);
    CarbonImmutable::setTestNow('2026-08-08 00:00:00 UTC');
});

afterEach(function (): void {
    config()->set('customer-health.connection');
    dropCustomerHealthStorage('testing');

    if (config('database.connections.tenant') !== null) {
        dropCustomerHealthStorage('tenant');
    }
});

it('computes every score for distinct event and milestone subjects in chunks', function (): void {
    $eventOnly = TestSubject::query()->create(['name' => 'Event only']);
    $both = TestSubject::query()->create(['name' => 'Both']);
    $milestoneOnly = TestSubject::query()->create(['name' => 'Milestone only']);
    CustomerHealth::track(new AccountCreated($eventOnly));
    CustomerHealth::track(new WorkflowCreated($both));
    Milestone::query()->create([
        'subject_type' => $milestoneOnly->getMorphClass(),
        'subject_id' => (string) $milestoneOnly->getKey(),
        'name' => 'manual_milestone',
        'occurred_at' => CarbonImmutable::now('UTC'),
    ]);

    expect(Artisan::call('customer-health:recompute', ['--chunk' => 1]))->toBe(Command::SUCCESS)
        ->and(HealthScoreRecord::query()->count())->toBe(6)
        ->and(HealthScoreRecord::query()->distinct()->pluck('subject_id')->all())
        ->toEqualCanonicalizing(array_map('strval', [$eventOnly->getKey(), $both->getKey(), $milestoneOnly->getKey()]));
});

it('filters by score key and subject identity', function (): void {
    $first = TestSubject::query()->create(['name' => 'First']);
    $second = TestSubject::query()->create(['name' => 'Second']);
    CustomerHealth::track(new AccountCreated($first));
    CustomerHealth::track(new AccountCreated($second));

    expect(Artisan::call('customer-health:recompute', [
        '--score' => 'primary',
        '--subject' => $first->getMorphClass().':'.$first->getKey(),
    ]))->toBe(Command::SUCCESS)
        ->and(HealthScoreRecord::query()->count())->toBe(1)
        ->and(HealthScoreRecord::query()->sole()->score)->toBe('primary')
        ->and(HealthScoreRecord::query()->sole()->subject_id)->toBe((string) $first->getKey());
});

it('reports a failing subject and continues with the rest', function (): void {
    config()->set('customer-health.scores', [SubjectAwareHealthScore::class]);
    $good = TestSubject::query()->create(['name' => 'Good']);
    $bad = TestSubject::query()->create(['name' => 'Bad']);
    CustomerHealth::track(new AccountCreated($good));
    CustomerHealth::track(new AccountCreated($bad));

    expect(Artisan::call('customer-health:recompute'))->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('Bad', (string) $bad->getKey())
        ->and(HealthScoreRecord::query()->where('subject_id', $good->getKey())->count())->toBe(1)
        ->and(HealthScoreRecord::query()->where('subject_id', $bad->getKey())->count())->toBe(0);
});

it('writes new history without repeating unchanged state transitions', function (): void {
    Event::fake([HealthStateChanged::class]);
    $subject = TestSubject::query()->create(['name' => 'Acme']);
    CustomerHealth::track(new AccountCreated($subject));

    expect(Artisan::call('customer-health:recompute', ['--score' => 'primary']))->toBe(Command::SUCCESS);
    Event::assertDispatchedTimes(HealthStateChanged::class, 1);

    expect(Artisan::call('customer-health:recompute', ['--score' => 'primary']))->toBe(Command::SUCCESS)
        ->and(HealthScoreRecord::query()->count())->toBe(2);
    Event::assertDispatchedTimes(HealthStateChanged::class, 1);
});

it('keeps package storage and application model connections independent', function (): void {
    $cleanup = configureCustomerHealthTenantDatabase();

    try {
        config()->set('customer-health.connection', 'tenant');
        runCustomerHealthStorageMigrations();
        $subject = TestSubject::query()->create(['name' => 'Application database']);
        CustomerHealth::track(new AccountCreated($subject));

        expect(Artisan::call('customer-health:recompute', ['--score' => 'primary']))->toBe(Command::SUCCESS)
            ->and(HealthScoreRecord::on('tenant')->count())->toBe(1)
            ->and(HealthScoreRecord::on('testing')->count())->toBe(0);
    } finally {
        config()->set('customer-health.connection');
        $cleanup();
    }
});

final class RecomputePrimaryScore extends HealthScore
{
    public static string $name = 'primary';

    public function signals(): array
    {
        return [new ConstantRecomputeSignal(80, 1)];
    }

    public function states(): array
    {
        return ['at_risk' => 0, 'healthy' => 75];
    }
}

final class RecomputeSecondaryScore extends HealthScore
{
    public static string $name = 'secondary';

    public function signals(): array
    {
        return [new ConstantRecomputeSignal(40, 1)];
    }

    public function states(): array
    {
        return ['at_risk' => 0, 'healthy' => 75];
    }
}

final readonly class ConstantRecomputeSignal implements Signal
{
    public function __construct(private int $value, private float $signalWeight) {}

    public function evaluate(Trackable $subject): int
    {
        return $this->value;
    }

    public function weight(): float
    {
        return $this->signalWeight;
    }
}

final class SubjectAwareHealthScore extends HealthScore
{
    public function signals(): array
    {
        return [new SubjectAwareSignal];
    }

    public function states(): array
    {
        return ['healthy' => 0];
    }
}

final class SubjectAwareSignal implements Signal
{
    public function evaluate(Trackable $subject): int
    {
        if ($subject instanceof TestSubject && $subject->name === 'Bad') {
            throw new RuntimeException('Bad subject cannot be scored.');
        }

        return 100;
    }

    public function weight(): float
    {
        return 1.0;
    }
}
