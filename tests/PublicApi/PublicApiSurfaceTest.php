<?php

declare(strict_types=1);

use ByRcsc\LaravelCustomerHealth\Commands\PurgeCustomerHealthCommand;
use ByRcsc\LaravelCustomerHealth\Commands\RecomputeHealthScoresCommand;
use ByRcsc\LaravelCustomerHealth\Concerns\TracksCustomerHealth;
use ByRcsc\LaravelCustomerHealth\Contracts\TenantResolver;
use ByRcsc\LaravelCustomerHealth\Contracts\Trackable;
use ByRcsc\LaravelCustomerHealth\CustomerHealthManager;
use ByRcsc\LaravelCustomerHealth\Events\HealthScoreComputed;
use ByRcsc\LaravelCustomerHealth\Events\HealthStateChanged;
use ByRcsc\LaravelCustomerHealth\Events\MilestoneReached;
use ByRcsc\LaravelCustomerHealth\Events\OnboardingCompleted;
use ByRcsc\LaravelCustomerHealth\Events\OnboardingStepCompleted;
use ByRcsc\LaravelCustomerHealth\Events\ProductEvent;
use ByRcsc\LaravelCustomerHealth\Events\ProductEventRecorded;
use ByRcsc\LaravelCustomerHealth\Exceptions\UnregisteredEventException;
use ByRcsc\LaravelCustomerHealth\Facades\CustomerHealth;
use ByRcsc\LaravelCustomerHealth\Models\HealthScoreRecord;
use ByRcsc\LaravelCustomerHealth\Models\HealthSummary;
use ByRcsc\LaravelCustomerHealth\Models\Milestone;
use ByRcsc\LaravelCustomerHealth\Models\ProductEventRecord;
use ByRcsc\LaravelCustomerHealth\Onboarding\Checklist;
use ByRcsc\LaravelCustomerHealth\Scoring\HealthScore;
use ByRcsc\LaravelCustomerHealth\Scoring\Signal;
use ByRcsc\LaravelCustomerHealth\Scoring\Signals\DistinctActors;
use ByRcsc\LaravelCustomerHealth\Scoring\Signals\FeatureActivity;
use ByRcsc\LaravelCustomerHealth\Scoring\Signals\FeatureAdopted;
use ByRcsc\LaravelCustomerHealth\Scoring\Signals\OnboardingProgress;
use ByRcsc\LaravelCustomerHealth\Scoring\Signals\RecentActivity;
use ByRcsc\LaravelCustomerHealth\Scoring\WindowedSignal;
use ByRcsc\LaravelCustomerHealth\Tenancy\SpatieTenantResolver;
use ByRcsc\LaravelCustomerHealth\ValueObjects\FeatureUsage;
use ByRcsc\LaravelCustomerHealth\ValueObjects\Progress;
use ByRcsc\LaravelCustomerHealth\ValueObjects\ScoreResult;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;

it('locks the public PHP types and their key method signatures', function (): void {
    $classes = [
        ProductEvent::class,
        Checklist::class,
        HealthScore::class,
        RecentActivity::class,
        FeatureAdopted::class,
        FeatureActivity::class,
        DistinctActors::class,
        OnboardingProgress::class,
        CustomerHealth::class,
        FeatureUsage::class,
        Progress::class,
        ScoreResult::class,
        ProductEventRecorded::class,
        MilestoneReached::class,
        OnboardingStepCompleted::class,
        OnboardingCompleted::class,
        HealthScoreComputed::class,
        HealthStateChanged::class,
        UnregisteredEventException::class,
        RecomputeHealthScoresCommand::class,
        PurgeCustomerHealthCommand::class,
        ProductEventRecord::class,
        Milestone::class,
        HealthScoreRecord::class,
        HealthSummary::class,
        SpatieTenantResolver::class,
    ];

    foreach ($classes as $class) {
        expect(class_exists($class))->toBeTrue("Missing public class [{$class}].");
    }

    expect(interface_exists(Trackable::class))->toBeTrue()
        ->and(interface_exists(Signal::class))->toBeTrue()
        ->and(interface_exists(WindowedSignal::class))->toBeTrue()
        ->and(interface_exists(TenantResolver::class))->toBeTrue()
        ->and(trait_exists(TracksCustomerHealth::class))->toBeTrue()
        ->and(is_subclass_of(CustomerHealth::class, Facade::class))->toBeTrue();

    $methods = [
        ProductEvent::class => [
            '__construct' => Trackable::class.' $subject, (Illuminate\\Contracts\\Auth\\Authenticatable&Illuminate\\Database\\Eloquent\\Model)|null $actor=default, array $properties=default, ?DateTimeInterface $occurredAt=default',
            'name' => 'static (): string',
        ],
        Checklist::class => ['steps' => '(): array', 'name' => 'static (): string', 'stepNames' => '(): array', 'stepForName' => '(string $name): string'],
        HealthScore::class => ['signals' => '(): array', 'states' => '(): array', 'name' => 'static (): string'],
        Signal::class => ['evaluate' => '('.Trackable::class.' $subject): int', 'weight' => '(): float'],
        WindowedSignal::class => ['windowDays' => '(): int'],
        TenantResolver::class => ['__invoke' => '(): string|int|null'],
        TracksCustomerHealth::class => [
            'productEvents' => '(): Illuminate\\Database\\Eloquent\\Relations\\MorphMany',
            'milestones' => '(): Illuminate\\Database\\Eloquent\\Relations\\MorphMany',
            'lastProductActivity' => '(): ?Carbon\\CarbonImmutable',
            'hasAdopted' => '(string $feature): bool',
        ],
        RecentActivity::class => ['__construct' => 'int $days, float $weight', 'evaluate' => '('.Trackable::class.' $subject): int', 'weight' => '(): float', 'windowDays' => '(): int'],
        FeatureAdopted::class => ['__construct' => 'string $feature, float $weight', 'evaluate' => '('.Trackable::class.' $subject): int', 'weight' => '(): float'],
        FeatureActivity::class => ['__construct' => 'string $feature, int $days, float $weight', 'evaluate' => '('.Trackable::class.' $subject): int', 'weight' => '(): float', 'windowDays' => '(): int'],
        DistinctActors::class => ['__construct' => 'int $days, float $weight', 'evaluate' => '('.Trackable::class.' $subject): int', 'weight' => '(): float', 'windowDays' => '(): int'],
        OnboardingProgress::class => ['__construct' => 'string $checklist, float $weight', 'evaluate' => '('.Trackable::class.' $subject): int', 'weight' => '(): float'],
        FeatureUsage::class => ['eventCount' => '(?int $days=default): int', 'distinctActors' => '(?int $days=default): int'],
        Progress::class => ['completedSteps' => '(): int', 'totalSteps' => '(): int', 'percent' => '(): int', 'currentStep' => '(): ?string', 'isComplete' => '(): bool', 'stalledSince' => '(): ?Carbon\\CarbonImmutable'],
        ScoreResult::class => ['fromRecord' => 'static ('.HealthScoreRecord::class.' $record): self'],
        SpatieTenantResolver::class => ['__invoke' => '(): string|int|null'],
        CustomerHealthManager::class => [
            'track' => '('.ProductEvent::class.' $event): void',
            'features' => '(): array',
            'hasAdopted' => '('.Trackable::class.' $subject, string $feature): bool',
            'featureUsage' => '(string $feature): ByRcsc\\LaravelCustomerHealth\\Queries\\FeatureUsageQuery',
            'lastSeen' => '('.Trackable::class.' $subject): ?Carbon\\CarbonImmutable',
            'inactive' => '(int $days): ByRcsc\\LaravelCustomerHealth\\Queries\\InactiveSubjectsQuery',
            'onboarding' => '('.Trackable::class.' $subject, ?string $checklist=default): '.Progress::class,
            'stalledInOnboarding' => '(int $days): ByRcsc\\LaravelCustomerHealth\\Queries\\StalledOnboardingQuery',
            'compute' => '('.Trackable::class.' $subject, ?string $score=default): '.ScoreResult::class,
            'score' => '('.Trackable::class.' $subject, ?string $score=default): ?'.ScoreResult::class,
            'scoreHistory' => '('.Trackable::class.' $subject, ?string $score=default): Illuminate\\Support\\Collection',
            'summaries' => '(): Illuminate\\Database\\Eloquent\\Builder',
            'inState' => '(string $state, ?string $score=default): Illuminate\\Database\\Eloquent\\Builder',
            'purge' => '('.Trackable::class.' $subject): void',
        ],
    ];

    $signature = static function (ReflectionMethod $method): string {
        $parameters = array_map(static function (ReflectionParameter $parameter): string {
            $value = (string) $parameter->getType().' $'.$parameter->getName();

            return $parameter->isDefaultValueAvailable() ? $value.'=default' : $value;
        }, $method->getParameters());
        $prefix = $method->isStatic() ? 'static ' : '';
        $return = $method->hasReturnType() ? ': '.$method->getReturnType() : '';

        return $method->isConstructor()
            ? implode(', ', $parameters)
            : $prefix.'('.implode(', ', $parameters).')'.$return;
    };

    foreach ($methods as $class => $signatures) {
        foreach ($signatures as $method => $expectedSignature) {
            $reflection = new ReflectionMethod($class, $method);

            expect($reflection->isPublic())->toBeTrue("[{$class}::{$method}] must remain public.")
                ->and($signature($reflection))->toBe($expectedSignature, "[{$class}::{$method}] signature changed.");
        }
    }

    $facadeDoc = (string) (new ReflectionClass(CustomerHealth::class))->getDocComment();
    foreach ([
        '@method static void track(ProductEvent $event)',
        '@method static array<string, list<class-string<ProductEvent>>> features()',
        '@method static bool hasAdopted(Trackable $subject, string $feature)',
        '@method static FeatureUsageQuery featureUsage(string $feature)',
        '@method static CarbonImmutable|null lastSeen(Trackable $subject)',
        '@method static InactiveSubjectsQuery inactive(int $days)',
        '@method static Progress onboarding(Trackable $subject, ?string $checklist = null)',
        '@method static StalledOnboardingQuery stalledInOnboarding(int $days)',
        '@method static ScoreResult compute(Trackable $subject, ?string $score = null)',
        '@method static ScoreResult|null score(Trackable $subject, ?string $score = null)',
        '@method static Collection<int, ScoreResult> scoreHistory(Trackable $subject, ?string $score = null)',
        '@method static Builder<HealthSummary> summaries()',
        '@method static Builder<HealthSummary> inState(string $state, ?string $score = null)',
        '@method static void purge(Trackable $subject)',
    ] as $declaration) {
        expect($facadeDoc)->toContain($declaration);
    }
});

it('locks every fired event public property', function (string $event, array $properties): void {
    $reflection = new ReflectionClass($event);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();

    $actual = [];
    foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        $actual[$property->getName()] = (string) $property->getType();
    }

    expect($actual)->toBe($properties);
})->with([
    [ProductEventRecorded::class, ['record' => ProductEventRecord::class]],
    [MilestoneReached::class, ['milestone' => Milestone::class]],
    [OnboardingStepCompleted::class, ['milestone' => Milestone::class, 'checklist' => 'string', 'step' => 'string']],
    [OnboardingCompleted::class, ['milestone' => Milestone::class, 'checklist' => 'string']],
    [HealthScoreComputed::class, ['record' => HealthScoreRecord::class, 'result' => ScoreResult::class]],
    [HealthStateChanged::class, ['record' => HealthScoreRecord::class, 'from' => '?string', 'to' => 'string']],
]);

it('registers the public commands with their full signatures', function (): void {
    $commands = Artisan::all();

    expect($commands)->toHaveKeys(['customer-health:recompute', 'customer-health:purge']);

    /** @var Command $recompute */
    $recompute = $commands['customer-health:recompute'];
    /** @var Command $purge */
    $purge = $commands['customer-health:purge'];

    $signature = static function (Command $command): string {
        $property = new ReflectionProperty($command, 'signature');
        $value = $property->getValue($command);

        return preg_replace('/\s+/', ' ', trim(is_string($value) ? $value : '')) ?? '';
    };

    expect($signature($recompute))->toBe('customer-health:recompute {--score= : Limit recomputation to one registered score key} {--subject= : Limit recomputation to one Type:id subject identity} {--chunk=500 : Number of known subjects to load at once}')
        ->and($signature($purge))->toBe('customer-health:purge {subject_type : Model class or morph alias} {subject_id : Model key} {--tenant= : Override the tenant id matched in landlord summaries}');
});

it('keeps every documented config key in the shipped config', function (): void {
    $config = require __DIR__.'/../../config/customer-health.php';

    expect(array_keys($config))->toBe([
        'table_names',
        'connection',
        'summary_connection',
        'events',
        'checklists',
        'scores',
        'tenant_resolver',
        'retention_days',
        'queue',
        'queue_connection',
        'queue_name',
    ])->and(array_keys($config['table_names']))->toBe([
        'events', 'milestones', 'scores', 'summaries',
    ]);
});

it('does not document internal final classes as public API', function (): void {
    $readme = (string) file_get_contents(__DIR__.'/../../README.md');
    $publicFinal = [
        RecentActivity::class, FeatureAdopted::class, FeatureActivity::class,
        DistinctActors::class, OnboardingProgress::class, CustomerHealth::class,
        FeatureUsage::class, Progress::class, ScoreResult::class,
        ProductEventRecorded::class, MilestoneReached::class,
        OnboardingStepCompleted::class, OnboardingCompleted::class,
        HealthScoreComputed::class, HealthStateChanged::class,
        UnregisteredEventException::class,
        RecomputeHealthScoresCommand::class, PurgeCustomerHealthCommand::class,
        ProductEventRecord::class, Milestone::class, HealthScoreRecord::class,
        HealthSummary::class, SpatieTenantResolver::class,
    ];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src'));

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace([__DIR__.'/../../src/', '/'], ['', '\\'], $file->getPathname());
        $class = 'ByRcsc\\LaravelCustomerHealth\\'.substr($relative, 0, -4);

        if (class_exists($class) && (new ReflectionClass($class))->isFinal() && ! in_array($class, $publicFinal, true)) {
            expect($readme)->not->toMatch('/\\b'.preg_quote(class_basename($class), '/').'\\b/');
        }
    }
});

it('keeps documented readable models as final Eloquent models', function (string $model): void {
    $reflection = new ReflectionClass($model);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isSubclassOf(Model::class))->toBeTrue();
})->with([ProductEventRecord::class, Milestone::class, HealthScoreRecord::class, HealthSummary::class]);
