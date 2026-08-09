<?php

declare(strict_types=1);

use ByRcsc\LaravelCustomerHealth\Facades\CustomerHealth;
use Illuminate\Support\Facades\DB;
use Workbench\App\CustomerHealth\Events\WorkflowCreated;
use Workbench\App\Models\Team;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;

$tenant = Tenant::where('name', 'Alpha')->firstOrFail();

$tenant->execute(function (): void {
    $team = Team::firstOrFail();
    $user = User::firstOrFail();

    $connection = DB::connection('tenant');
    $connection->beginTransaction();

    try {
        CustomerHealth::track(new WorkflowCreated($team, actor: $user));
    } finally {
        $connection->rollBack();
    }

    CustomerHealth::hasAdopted($team, 'workflows');
    CustomerHealth::featureUsage('workflows')->for($team);
    CustomerHealth::lastSeen($team);
    CustomerHealth::inactive(days: 14)->get();

    $progress = CustomerHealth::onboarding($team);
    $progress->completedSteps();
    $progress->stalledSince();

    CustomerHealth::compute($team);
    CustomerHealth::score($team);
    CustomerHealth::scoreHistory($team);
    CustomerHealth::inState('at_risk')->get();
});
