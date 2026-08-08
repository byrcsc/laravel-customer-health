<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Facades;

use ByRcsc\LaravelCustomerHealth\Contracts\Trackable;
use ByRcsc\LaravelCustomerHealth\CustomerHealthManager;
use ByRcsc\LaravelCustomerHealth\Events\ProductEvent;
use ByRcsc\LaravelCustomerHealth\Models\HealthSummary;
use ByRcsc\LaravelCustomerHealth\Queries\FeatureUsageQuery;
use ByRcsc\LaravelCustomerHealth\Queries\InactiveSubjectsQuery;
use ByRcsc\LaravelCustomerHealth\Queries\StalledOnboardingQuery;
use ByRcsc\LaravelCustomerHealth\ValueObjects\Progress;
use ByRcsc\LaravelCustomerHealth\ValueObjects\ScoreResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void track(ProductEvent $event)
 * @method static array<string, list<class-string<ProductEvent>>> features()
 * @method static bool hasAdopted(Trackable $subject, string $feature)
 * @method static FeatureUsageQuery featureUsage(string $feature)
 * @method static CarbonImmutable|null lastSeen(Trackable $subject)
 * @method static InactiveSubjectsQuery inactive(int $days)
 * @method static Progress onboarding(Trackable $subject, ?string $checklist = null)
 * @method static StalledOnboardingQuery stalledInOnboarding(int $days)
 * @method static ScoreResult compute(Trackable $subject, ?string $score = null)
 * @method static ScoreResult|null score(Trackable $subject, ?string $score = null)
 * @method static Collection<int, ScoreResult> scoreHistory(Trackable $subject, ?string $score = null)
 * @method static Builder<HealthSummary> summaries()
 * @method static Builder<HealthSummary> inState(string $state, ?string $score = null)
 * @method static void purge(Trackable $subject)
 *
 * @see CustomerHealthManager
 */
final class CustomerHealth extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CustomerHealthManager::class;
    }
}
