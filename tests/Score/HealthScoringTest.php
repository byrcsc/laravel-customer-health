<?php

declare(strict_types=1);

use ByRcsc\LaravelCustomerHealth\Contracts\Trackable;
use ByRcsc\LaravelCustomerHealth\Data\MorphIdentity;
use ByRcsc\LaravelCustomerHealth\Events\HealthScoreComputed;
use ByRcsc\LaravelCustomerHealth\Events\HealthStateChanged;
use ByRcsc\LaravelCustomerHealth\Exceptions\InvalidScoreDefinitionException;
use ByRcsc\LaravelCustomerHealth\Facades\CustomerHealth;
use ByRcsc\LaravelCustomerHealth\Models\HealthScoreRecord;
use ByRcsc\LaravelCustomerHealth\Registry\HealthScoreRegistry;
use ByRcsc\LaravelCustomerHealth\Scoring\HealthScore;
use ByRcsc\LaravelCustomerHealth\Scoring\Signal;
use ByRcsc\LaravelCustomerHealth\Scoring\Signals\DistinctActors;
use ByRcsc\LaravelCustomerHealth\Scoring\Signals\FeatureActivity;
use ByRcsc\LaravelCustomerHealth\Scoring\Signals\FeatureAdopted;
use ByRcsc\LaravelCustomerHealth\Scoring\Signals\OnboardingProgress;
use ByRcsc\LaravelCustomerHealth\Scoring\Signals\RecentActivity;
use ByRcsc\LaravelCustomerHealth\Support\ScoreComputationLock;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\TeammateInvited;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\TestActor;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\TestOnboarding;
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
    config()->set('customer-health.events', [WorkflowCreated::class, TeammateInvited::class]);
    config()->set('customer-health.checklists', [TestOnboarding::class]);
    config()->set('customer-health.scores', [MutableHealthScore::class, PropertyHealthScore::class]);
    CarbonImmutable::setTestNow('2026-08-08 00:00:00 UTC');
});

afterEach(function (): void {
    MutableSignal::$value = 0;
    PropertyHealthScore::$values = [0, 0, 0];
    PropertyHealthScore::$weights = [1.0, 1.0, 1.0];
    DynamicSignalsHealthScore::$invalid = false;
    DynamicSignalsHealthScore::$invalidStates = false;
    config()->set('customer-health.connection');
    dropCustomerHealthStorage('testing');

    if (config('database.connections.tenant') !== null) {
        dropCustomerHealthStorage('tenant');
    }
});

it('normalizes weights independently of their scale', function (): void {
    $subject = TestSubject::query()->create(['name' => 'Acme']);

    for ($iteration = 0; $iteration < 40; $iteration++) {
        PropertyHealthScore::$values = [random_int(0, 100), random_int(0, 100), random_int(0, 100)];
        PropertyHealthScore::$weights = [random_int(1, 100), random_int(1, 100), random_int(1, 100)];
        $base = CustomerHealth::compute($subject, 'property');
        $factor = random_int(2, 20);
        PropertyHealthScore::$weights = array_map(
            fn (float $weight): float => $weight * $factor,
            PropertyHealthScore::$weights,
        );
        $scaled = CustomerHealth::compute($subject, 'property');

        expect($scaled->value)->toBe($base->value);
    }
});

it('stores explainable history and fires only real state transitions', function (): void {
    Event::fake([HealthScoreComputed::class, HealthStateChanged::class]);
    $subject = TestSubject::query()->create(['name' => 'Acme']);
    expect(CustomerHealth::score($subject, 'mutable'))->toBeNull();

    MutableSignal::$value = 20;
    $first = CustomerHealth::compute($subject, 'mutable');
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHour());
    MutableSignal::$value = 30;
    CustomerHealth::compute($subject, 'mutable');
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHour());
    MutableSignal::$value = 80;
    $third = CustomerHealth::compute($subject, 'mutable');

    expect($first->state)->toBe('at_risk')
        ->and($third->state)->toBe('healthy')
        ->and($third->breakdown)->toBe([[
            'signal' => MutableSignal::class,
            'raw' => 80,
            'weight' => 1.0,
            'contribution' => 80.0,
        ]])
        ->and(CustomerHealth::score($subject, 'mutable')?->value)->toBe(80)
        ->and(CustomerHealth::scoreHistory($subject, 'mutable')->pluck('value')->all())->toBe([20, 30, 80])
        ->and(CustomerHealth::scoreHistory($subject, 'mutable')->first()?->computedAt->isUtc())->toBeTrue();

    Event::assertDispatchedTimes(HealthScoreComputed::class, 3);
    Event::assertDispatchedTimes(HealthStateChanged::class, 2);
    Event::assertDispatched(fn (HealthStateChanged $event): bool => $event->from === null && $event->to === 'at_risk');
    Event::assertDispatched(fn (HealthStateChanged $event): bool => $event->from === 'at_risk' && $event->to === 'healthy');
});

it('evaluates every built in signal at inclusive UTC window boundaries', function (): void {
    $subject = TestSubject::query()->create(['name' => 'Acme']);
    $actor = TestActor::query()->create(['name' => 'Taylor']);

    expect((new RecentActivity(days: 7, weight: 1))->evaluate($subject))->toBe(0)
        ->and((new FeatureAdopted('workflows', weight: 1))->evaluate($subject))->toBe(0)
        ->and((new FeatureActivity('workflows', days: 7, weight: 1))->evaluate($subject))->toBe(0)
        ->and((new DistinctActors(days: 7, weight: 1))->evaluate($subject))->toBe(0)
        ->and((new OnboardingProgress(TestOnboarding::class, weight: 1))->evaluate($subject))->toBe(0);

    CustomerHealth::track(new WorkflowCreated(
        $subject,
        actor: $actor,
        occurredAt: CarbonImmutable::now('UTC')->subDays(7),
    ));

    expect((new RecentActivity(days: 7, weight: 1))->evaluate($subject))->toBe(100)
        ->and((new FeatureAdopted('workflows', weight: 1))->evaluate($subject))->toBe(100)
        ->and((new FeatureActivity('workflows', days: 7, weight: 1))->evaluate($subject))->toBe(100)
        ->and((new DistinctActors(days: 7, weight: 1))->evaluate($subject))->toBe(100)
        ->and((new OnboardingProgress(TestOnboarding::class, weight: 1))->evaluate($subject))->toBe(50);

    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSecond());
    expect((new RecentActivity(days: 7, weight: 1))->evaluate($subject))->toBe(0)
        ->and((new FeatureAdopted('workflows', weight: 1))->evaluate($subject))->toBe(100)
        ->and((new FeatureActivity('workflows', days: 7, weight: 1))->evaluate($subject))->toBe(0)
        ->and((new DistinctActors(days: 7, weight: 1))->evaluate($subject))->toBe(0);

    CustomerHealth::track(new TeammateInvited($subject));
    expect((new OnboardingProgress(TestOnboarding::class, weight: 1))->evaluate($subject))->toBe(100);
});

it('stores nothing when a signal throws', function (): void {
    config()->set('customer-health.scores', [ThrowingHealthScore::class]);
    $subject = TestSubject::query()->create(['name' => 'Acme']);

    expect(fn () => CustomerHealth::compute($subject))->toThrow(RuntimeException::class, 'signal failed')
        ->and(HealthScoreRecord::query()->count())->toBe(0);
});

it('validates the signal and state snapshots used by each computation', function (): void {
    $registry = new HealthScoreRegistry([DynamicSignalsHealthScore::class]);
    DynamicSignalsHealthScore::$invalid = true;

    expect(fn (): array => $registry->weightedSignals($registry->resolve()))
        ->toThrow(InvalidScoreDefinitionException::class);

    DynamicSignalsHealthScore::$invalid = false;
    DynamicSignalsHealthScore::$invalidStates = true;
    expect(fn (): string => $registry->stateFor($registry->resolve(), 50))
        ->toThrow(InvalidScoreDefinitionException::class);
});

it('serializes state transitions when computations race', function (): void {
    if (config('database.connections.testing.driver') === 'sqlite') {
        $this->markTestSkipped('The score transition race test runs on MySQL and PostgreSQL CI jobs.');
    }

    Schema::create('score_dispatches', function (Blueprint $table): void {
        $table->id();
        $table->string('from')->nullable();
        $table->string('to');
    });
    Event::listen(HealthStateChanged::class, function (HealthStateChanged $event): void {
        DB::table('score_dispatches')->insert(['from' => $event->from, 'to' => $event->to]);
    });
    $subject = TestSubject::query()->create(['name' => 'Acme']);
    MutableSignal::$value = 80;
    $children = [];

    for ($index = 0; $index < 2; $index++) {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new RuntimeException('Unable to create score race barrier.');
        }
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork score worker.');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            fread($sockets[1], 1);
            fclose($sockets[1]);
            try {
                DB::disconnect('testing');
                DB::reconnect('testing');
                $workerSubject = TestSubject::on('testing')->findOrFail($subject->getKey());
                MutableSignal::$value = $index === 0 ? 20 : 80;
                CustomerHealth::compute($workerSubject, 'mutable');
                exit(0);
            } catch (Throwable) {
                exit(1);
            }
        }

        fclose($sockets[1]);
        $children[] = [$pid, $sockets[0]];
    }

    foreach ($children as [, $socket]) {
        fwrite($socket, 'g');
        fclose($socket);
    }
    foreach ($children as [$pid]) {
        pcntl_waitpid($pid, $status);
        expect(pcntl_wexitstatus($status))->toBe(0);
    }
    DB::disconnect('testing');
    DB::reconnect('testing');

    $states = HealthScoreRecord::query()->where('score', 'mutable')
        ->oldest('computed_at')->oldest('id')->pluck('state')->all();
    $dispatches = DB::table('score_dispatches')->orderBy('id')->get();

    expect($states)->toHaveCount(2)
        ->and($dispatches)->toHaveCount(2)
        ->and($dispatches[0]->from)->toBeNull()
        ->and($dispatches[0]->to)->toBe($states[0])
        ->and($dispatches[1]->from)->toBe($states[0])
        ->and($dispatches[1]->to)->toBe($states[1]);
});

it('timestamps a computation after it acquires the serialization lock', function (): void {
    if (config('database.connections.testing.driver') === 'sqlite') {
        $this->markTestSkipped('Score lock timing is verified by MySQL and PostgreSQL CI jobs.');
    }

    CarbonImmutable::setTestNow();
    $subject = TestSubject::query()->create(['name' => 'Acme']);
    MutableSignal::$value = 80;
    $identity = MorphIdentity::from($subject, 'subject');
    $key = json_encode([$identity->type, $identity->id, 'mutable'], JSON_THROW_ON_ERROR);
    $connection = DB::connection('testing');
    $connection->beginTransaction();
    app(ScoreComputationLock::class)->acquire($connection, $key);
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($sockets === false) {
        throw new RuntimeException('Unable to create score lock barrier.');
    }
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Unable to fork score lock worker.');
    }

    if ($pid === 0) {
        fclose($sockets[0]);
        try {
            DB::disconnect('testing');
            DB::reconnect('testing');
            fwrite($sockets[1], 'r');
            fclose($sockets[1]);
            $workerSubject = TestSubject::on('testing')->findOrFail($subject->getKey());
            CustomerHealth::compute($workerSubject, 'mutable');
            exit(0);
        } catch (Throwable) {
            exit(1);
        }
    }

    fclose($sockets[1]);
    fread($sockets[0], 1);
    fclose($sockets[0]);
    usleep(1_200_000);
    $releasedAt = CarbonImmutable::now('UTC')->startOfSecond();
    $connection->commit();
    pcntl_waitpid($pid, $status);
    expect(pcntl_wexitstatus($status))->toBe(0);
    DB::disconnect('testing');
    DB::reconnect('testing');

    expect(HealthScoreRecord::query()->sole()->computed_at->greaterThanOrEqualTo($releasedAt))->toBeTrue();
});

it('writes and reads score history on the configured connection', function (): void {
    $cleanup = configureCustomerHealthTenantDatabase();

    try {
        config()->set('customer-health.connection', 'tenant');
        runCustomerHealthStorageMigrations();
        createTrackableFixtures('tenant');
        $subject = TestSubject::on('tenant')->create(['name' => 'Tenant']);
        MutableSignal::$value = 75;

        CustomerHealth::compute($subject, 'mutable');

        expect(HealthScoreRecord::on('tenant')->count())->toBe(1)
            ->and(CustomerHealth::score($subject, 'mutable')?->value)->toBe(75)
            ->and(HealthScoreRecord::on('testing')->count())->toBe(0);
    } finally {
        config()->set('customer-health.connection');
        $cleanup();
    }
});

final class MutableHealthScore extends HealthScore
{
    public static string $name = 'mutable';

    public function signals(): array
    {
        return [new MutableSignal(1)];
    }

    public function states(): array
    {
        return ['at_risk' => 0, 'healthy' => 75];
    }
}

final class MutableSignal implements Signal
{
    public static int $value = 0;

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

final class PropertyHealthScore extends HealthScore
{
    public static string $name = 'property';

    /** @var list<int> */
    public static array $values = [0, 0, 0];

    /** @var list<float> */
    public static array $weights = [1.0, 1.0, 1.0];

    public function signals(): array
    {
        return array_map(
            fn (int $value, float $weight): Signal => new FixedSignal($value, $weight),
            self::$values,
            self::$weights,
        );
    }

    public function states(): array
    {
        return ['low' => 0];
    }
}

final readonly class FixedSignal implements Signal
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

final class ThrowingHealthScore extends HealthScore
{
    public function signals(): array
    {
        return [new ThrowingSignal];
    }

    public function states(): array
    {
        return ['unknown' => 0];
    }
}

final class ThrowingSignal implements Signal
{
    public function evaluate(Trackable $subject): int
    {
        throw new RuntimeException('signal failed');
    }

    public function weight(): float
    {
        return 1.0;
    }
}

final class DynamicSignalsHealthScore extends HealthScore
{
    public static bool $invalid = false;

    public static bool $invalidStates = false;

    public function signals(): array
    {
        return self::$invalid ? [] : [new FixedSignal(50, 1)];
    }

    public function states(): array
    {
        return self::$invalidStates ? [] : ['healthy' => 0];
    }
}
