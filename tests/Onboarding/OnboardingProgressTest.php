<?php

declare(strict_types=1);

use ByRcsc\LaravelCustomerHealth\Events\OnboardingCompleted;
use ByRcsc\LaravelCustomerHealth\Events\OnboardingStepCompleted;
use ByRcsc\LaravelCustomerHealth\Exceptions\InvalidChecklistDefinitionException;
use ByRcsc\LaravelCustomerHealth\Exceptions\UnregisteredEventException;
use ByRcsc\LaravelCustomerHealth\Facades\CustomerHealth;
use ByRcsc\LaravelCustomerHealth\Models\Milestone;
use ByRcsc\LaravelCustomerHealth\Onboarding\Checklist;
use ByRcsc\LaravelCustomerHealth\Registry\ChecklistRegistry;
use ByRcsc\LaravelCustomerHealth\Support\TableNames;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\AccountCreated;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\TeammateInvited;
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
    CarbonImmutable::setTestNow('2026-08-08 00:00:00 UTC');
});

afterEach(function (): void {
    config()->set('customer-health.connection');
    dropCustomerHealthStorage('testing');

    if (config('database.connections.tenant') !== null) {
        dropCustomerHealthStorage('tenant');
    }
});

it('derives ordered progress and completion from milestones', function (): void {
    Event::fake([OnboardingStepCompleted::class, OnboardingCompleted::class]);
    $subject = TestSubject::query()->create(['name' => 'Acme']);

    $empty = CustomerHealth::onboarding($subject);
    expect($empty->completedSteps())->toBe(0)->and($empty->totalSteps())->toBe(2)
        ->and($empty->percent())->toBe(0)->and($empty->currentStep())->toBe(WorkflowCreated::class)
        ->and($empty->stalledSince())->toBeNull();

    CustomerHealth::track(new TeammateInvited($subject, occurredAt: CarbonImmutable::now('UTC')->subDay()));
    $partial = CustomerHealth::onboarding($subject);
    expect($partial->completedSteps())->toBe(1)->and($partial->percent())->toBe(50)
        ->and($partial->currentStep())->toBe(WorkflowCreated::class)
        ->and($partial->stalledSince()?->equalTo(CarbonImmutable::now('UTC')->subDay()))->toBeTrue();

    CustomerHealth::track(new WorkflowCreated($subject));
    $complete = CustomerHealth::onboarding($subject);
    expect($complete->isComplete())->toBeTrue()->and($complete->percent())->toBe(100)
        ->and($complete->currentStep())->toBeNull()->and($complete->stalledSince())->toBeNull()
        ->and(Milestone::query()->where('name', 'onboarding:test_onboarding')->count())->toBe(1);

    Event::assertDispatchedTimes(OnboardingStepCompleted::class, 2);
    Event::assertDispatchedTimes(OnboardingCompleted::class, 1);
});

it('keeps progress after raw events are deleted and finds stalled subjects', function (): void {
    $subject = TestSubject::query()->create(['name' => 'Acme']);
    CustomerHealth::track(new WorkflowCreated($subject, occurredAt: CarbonImmutable::now('UTC')->subDays(15)));
    $subject->productEvents()->delete();

    expect(CustomerHealth::onboarding($subject)->completedSteps())->toBe(1)
        ->and(CustomerHealth::stalledInOnboarding(14)->get()->sole()->id)->toBe((string) $subject->getKey());
});

it('treats the exact stall boundary as active', function (): void {
    $subject = TestSubject::query()->create(['name' => 'Boundary']);
    CustomerHealth::track(new WorkflowCreated($subject, occurredAt: CarbonImmutable::now('UTC')->subDays(14)));

    expect(CustomerHealth::stalledInOnboarding(14)->get())->toBeEmpty();
});

it('rejects duplicate checklist steps', function (): void {
    config()->set('customer-health.checklists', [DuplicateStepsChecklist::class]);
    app(ChecklistRegistry::class);
})->throws(InvalidChecklistDefinitionException::class);

it('rejects unregistered checklist steps', function (): void {
    config()->set('customer-health.checklists', [NonMilestoneChecklist::class]);
    app(ChecklistRegistry::class);
})->throws(UnregisteredEventException::class);

it('rejects registered steps that are not milestones', function (): void {
    config()->set('customer-health.events', [WorkflowCreated::class, TeammateInvited::class, AccountCreated::class]);
    config()->set('customer-health.checklists', [NonMilestoneChecklist::class]);
    app(ChecklistRegistry::class);
})->throws(InvalidChecklistDefinitionException::class);

it('records one completion when the final milestone races', function (): void {
    if (config('database.connections.testing.driver') === 'sqlite') {
        $this->markTestSkipped('The onboarding race test runs on MySQL and PostgreSQL CI jobs.');
    }

    Schema::create('onboarding_dispatches', function (Blueprint $table): void {
        $table->id();
        $table->string('event');
    });
    Event::listen(OnboardingCompleted::class, function (): void {
        DB::table('onboarding_dispatches')->insert(['event' => 'completed']);
    });
    $subject = TestSubject::query()->create(['name' => 'Acme']);
    $children = [];

    for ($index = 0; $index < 2; $index++) {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new RuntimeException('Unable to create onboarding race barrier.');
        }
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork onboarding worker.');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            try {
                DB::disconnect('testing');
                DB::reconnect('testing');
                $workerSubject = TestSubject::on('testing')->findOrFail($subject->getKey());
                $event = $index === 0 ? new WorkflowCreated($workerSubject) : new TeammateInvited($workerSubject);
                DB::connection('testing')->beginTransaction();
                CustomerHealth::track($event);
                fwrite($sockets[1], 'r');
                fread($sockets[1], 1);
                DB::connection('testing')->commit();
                fclose($sockets[1]);
                exit(0);
            } catch (Throwable) {
                if (DB::connection('testing')->transactionLevel() > 0) {
                    DB::connection('testing')->rollBack();
                }
                exit(1);
            }
        }

        fclose($sockets[1]);
        $children[] = [$pid, $sockets[0]];
    }

    foreach ($children as [, $socket]) {
        fread($socket, 1);
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

    expect(Milestone::query()->where('name', 'onboarding:test_onboarding')->count())->toBe(1)
        ->and(Milestone::query()->whereIn('name', ['workflow_created', 'teammate_invited'])->count())->toBe(2)
        ->and(DB::table('onboarding_dispatches')->count())->toBe(1);
});

it('reconciles a missing completion when a milestone delivery retries', function (): void {
    Event::fake([OnboardingStepCompleted::class, OnboardingCompleted::class]);
    $subject = TestSubject::query()->create(['name' => 'Acme']);
    CustomerHealth::track(new WorkflowCreated($subject));
    CustomerHealth::track(new TeammateInvited($subject));
    Milestone::query()->where('name', 'onboarding:test_onboarding')->delete();

    CustomerHealth::track(new TeammateInvited($subject));

    expect(Milestone::query()->where('name', 'onboarding:test_onboarding')->count())->toBe(1);
    Event::assertDispatchedTimes(OnboardingStepCompleted::class, 2);
    Event::assertDispatchedTimes(OnboardingCompleted::class, 2);
});

it('uses the package connection for progress completion and stalled queries', function (): void {
    $cleanup = configureCustomerHealthTenantDatabase();

    try {
        config()->set('customer-health.connection', 'tenant');
        runCustomerHealthStorageMigrations();
        createTrackableFixtures('tenant');
        $subject = TestSubject::on('tenant')->create(['name' => 'Tenant']);

        CustomerHealth::track(new WorkflowCreated($subject));
        expect(CustomerHealth::onboarding($subject)->completedSteps())->toBe(1);

        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(15));
        $reference = CustomerHealth::stalledInOnboarding(14)->get()->sole();
        expect($reference->resolve('tenant')?->is($subject))->toBeTrue();

        CustomerHealth::track(new TeammateInvited($subject));
        expect(CustomerHealth::onboarding($subject)->isComplete())->toBeTrue()
            ->and(Milestone::on('tenant')->where('name', 'onboarding:test_onboarding')->count())->toBe(1);
    } finally {
        config()->set('customer-health.connection');
        $cleanup();
    }
});

it('keeps deferred reconciliation on the connection that recorded the milestone', function (): void {
    $cleanup = configureCustomerHealthTenantDatabase();

    try {
        config()->set('customer-health.connection', 'tenant');
        runCustomerHealthStorageMigrations();
        config()->set('customer-health.connection');
        $subject = TestSubject::query()->create(['name' => 'Original tenant']);
        $connection = DB::connection('testing');
        $connection->beginTransaction();

        CustomerHealth::track(new WorkflowCreated($subject));
        CustomerHealth::track(new TeammateInvited($subject));
        config()->set('customer-health.connection', 'tenant');
        $connection->commit();

        expect(Milestone::on('testing')->where('name', 'onboarding:test_onboarding')->count())->toBe(1)
            ->and(Milestone::on('tenant')->count())->toBe(0);
    } finally {
        if (DB::connection('testing')->transactionLevel() > 0) {
            DB::connection('testing')->rollBack();
        }
        config()->set('customer-health.connection');
        $cleanup();
    }
});

it('uses the milestone name and time index for stalled aggregation on mysql', function (): void {
    if (config('database.connections.testing.driver') !== 'mysql') {
        $this->markTestSkipped('Onboarding index selection is verified by MySQL CI jobs.');
    }

    $rows = [];
    for ($index = 1; $index <= 2_000; $index++) {
        $rows[] = [
            'subject_type' => TestSubject::class,
            'subject_id' => (string) $index,
            'name' => $index <= 200
                ? ($index % 2 === 0 ? 'workflow_created' : 'teammate_invited')
                : 'unrelated_event_'.$index,
            'actor_type' => null,
            'actor_id' => null,
            'occurred_at' => CarbonImmutable::now('UTC')->subDays(30),
            'created_at' => CarbonImmutable::now('UTC'),
        ];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table(TableNames::milestones())->insert($chunk);
    }

    $query = Milestone::query()
        ->whereIn('name', ['workflow_created', 'teammate_invited'])
        ->groupBy(['subject_type', 'subject_id'])
        ->havingRaw('COUNT(DISTINCT name) < ?', [2])
        ->havingRaw('MAX(occurred_at) < ?', [CarbonImmutable::now('UTC')->subDays(14)])
        ->select(['subject_type', 'subject_id']);
    $plan = DB::select('EXPLAIN '.$query->toSql(), $query->getBindings())[0];

    expect($plan->type)->not->toBe('ALL')
        ->and($plan->key)->toBe('ch_milestones_name_time_idx');
});

final class DuplicateStepsChecklist extends Checklist
{
    public function steps(): array
    {
        return [WorkflowCreated::class, WorkflowCreated::class];
    }
}

final class NonMilestoneChecklist extends Checklist
{
    public function steps(): array
    {
        return [AccountCreated::class];
    }
}
