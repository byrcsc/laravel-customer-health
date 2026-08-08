<?php

declare(strict_types=1);

namespace Workbench\App\CustomerHealth;

use ByRcsc\LaravelCustomerHealth\Scoring\HealthScore;
use ByRcsc\LaravelCustomerHealth\Scoring\Signals\DistinctActors;
use ByRcsc\LaravelCustomerHealth\Scoring\Signals\FeatureAdopted;
use ByRcsc\LaravelCustomerHealth\Scoring\Signals\OnboardingProgress;
use ByRcsc\LaravelCustomerHealth\Scoring\Signals\RecentActivity;

final class CustomerHealthScore extends HealthScore
{
    public function signals(): array
    {
        return [
            new RecentActivity(days: 7, weight: 30),
            new FeatureAdopted('workflows', weight: 30),
            new DistinctActors(days: 30, weight: 20),
            new OnboardingProgress(Onboarding::class, weight: 20),
        ];
    }

    public function states(): array
    {
        return ['at_risk' => 0, 'watch' => 50, 'healthy' => 75];
    }
}
