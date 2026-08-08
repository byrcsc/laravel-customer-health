# Workbench

The workbench drives the package through a database-per-tenant Spatie v4 app.
It uses SQLite so the full integration loop needs no external services:

- `landlord` stores tenants and customer health summaries.
- each of Alpha and Beta has its own database selected through the `tenant`
  connection by `SwitchTenantDatabaseTask`.
- `Workbench\App\Models\Team` is the trackable subject and
  `Workbench\App\Models\User` supplies actors.

Alpha completes onboarding and adopts workflows. Beta completes only the
first onboarding milestone 45 days ago, so recomputation puts Alpha in
`healthy` and Beta in `at_risk`.

## Build from clean

```bash
composer clear
composer build
```

The build recreates the landlord database and both tenant databases, runs the
package migrations on the appropriate connections, and seeds the complete
story. Re-running these two commands resets every demo row.

The declarations are deliberately the same as the README examples:

- events: `app/CustomerHealth/Events/AccountCreated.php`,
  `WorkflowCreated.php`, and `TeammateInvited.php`;
- checklist: `app/CustomerHealth/Onboarding.php`;
- score using the four built-in signals: `app/CustomerHealth/CustomerHealthScore.php`;
- package registration: `config/customer-health.php`;
- check-in listener: `app/Listeners/NotifyCustomerSuccess.php`.

## 1. Inspect seeded onboarding and adoption

Run the README query calls inside Alpha's tenant context:

```bash
vendor/bin/testbench tinker --execute='
$tenant = Workbench\App\Models\Tenant::where("name", "Alpha")->firstOrFail();
$tenant->execute(function (): void {
    $team = Workbench\App\Models\Team::firstOrFail();
    $progress = ByRcsc\LaravelCustomerHealth\Facades\CustomerHealth::onboarding($team);

    dump([
        "features" => ByRcsc\LaravelCustomerHealth\Facades\CustomerHealth::features(),
        "adopted_workflows" => ByRcsc\LaravelCustomerHealth\Facades\CustomerHealth::hasAdopted($team, "workflows"),
        "workflow_events" => ByRcsc\LaravelCustomerHealth\Facades\CustomerHealth::featureUsage("workflows")->for($team)->eventCount(),
        "completed_steps" => $progress->completedSteps(),
        "total_steps" => $progress->totalSteps(),
    ]);
});'
```

Expected: workflow adoption is `true`, there are two workflow events, and
onboarding is `3/3`. Change `Alpha` to `Beta`; adoption is `false` and
onboarding is `1/3`.

## 2. Recompute every tenant and watch the check-in

```bash
vendor/bin/testbench tenants:artisan customer-health:recompute
tail -n 20 vendor/orchestra/testbench-core/laravel/storage/logs/laravel.log
```

The command writes history in each tenant database and summaries in the
landlord database. The log contains the README's
`Customer entered an at-risk health state.` message for Beta from
`NotifyCustomerSuccess`.

## 3. Query at-risk customers from the landlord

```bash
vendor/bin/testbench tinker --execute='
dump(ByRcsc\LaravelCustomerHealth\Facades\CustomerHealth::inState("at_risk")
    ->get(["tenant_id", "subject_type", "subject_id", "value", "state"])
    ->toArray());'
```

Expected: one summary with tenant id `2`, subject id `1`, and state `at_risk`.
No tenant database is selected for this query.

## 4. Prune raw history without losing adoption

Beta's 45-day-old event is beyond the configured 30-day retention window.
Prune each tenant, then verify that milestone-backed adoption remains
unchanged:

Spatie's nested command currently escapes FQCN options, so enter each tenant
context and call Laravel's prune command with its array form:

```bash
vendor/bin/testbench tinker --execute='
$tenants = Workbench\App\Models\Tenant::all();
$tenants->each(function ($tenant): void {
    $tenant->execute(function (): void {
        Illuminate\Support\Facades\Artisan::call("model:prune", [
            "--model" => [ByRcsc\LaravelCustomerHealth\Models\ProductEventRecord::class],
        ]);
        dump(Illuminate\Support\Facades\Artisan::output());
    });
});'

vendor/bin/testbench tinker --execute='
$tenant = Workbench\App\Models\Tenant::where("name", "Beta")->firstOrFail();
$tenant->execute(function (): void {
    $team = Workbench\App\Models\Team::firstOrFail();

    dump([
        "raw_events" => ByRcsc\LaravelCustomerHealth\Models\ProductEventRecord::count(),
        "adopted_accounts" => ByRcsc\LaravelCustomerHealth\Facades\CustomerHealth::hasAdopted($team, "accounts"),
        "onboarding_steps" => ByRcsc\LaravelCustomerHealth\Facades\CustomerHealth::onboarding($team)->completedSteps(),
    ]);
});'
```

Expected: Beta has zero raw events, but account adoption remains `true` and
onboarding remains at one completed step.

## 5. Purge one subject and verify erasure

```bash
vendor/bin/testbench tinker --execute='
$tenant = Workbench\App\Models\Tenant::findOrFail(2);
$tenant->execute(function (): void {
    Illuminate\Support\Facades\Artisan::call("customer-health:purge", [
        "subject_type" => Workbench\App\Models\Team::class,
        "subject_id" => "1",
    ]);
    dump(Illuminate\Support\Facades\Artisan::output());
});'

vendor/bin/testbench tinker --execute='
$tenant = Workbench\App\Models\Tenant::findOrFail(2);
$tenant->execute(function (): void {
    dump([
        "events" => ByRcsc\LaravelCustomerHealth\Models\ProductEventRecord::count(),
        "milestones" => ByRcsc\LaravelCustomerHealth\Models\Milestone::count(),
        "scores" => ByRcsc\LaravelCustomerHealth\Models\HealthScoreRecord::count(),
    ]);
});

dump([
    "summaries" => ByRcsc\LaravelCustomerHealth\Models\HealthSummary::where("tenant_id", "2")->count(),
    "at_risk" => ByRcsc\LaravelCustomerHealth\Facades\CustomerHealth::inState("at_risk")->count(),
]);'
```

Expected: every count is zero. Alpha's tenant data and healthy landlord
summary remain untouched.

## Run the README API samples

After the state-sensitive loop, run every executable Quick Start expression
unchanged in the booted demo:

```bash
vendor/bin/testbench tinker workbench/readme-api-examples.php \
  --execute='dump("README API samples completed.");'
```

The include runs the sample tracking call in a rollback-only transaction so
the seeded counts remain repeatable. It then runs the query and onboarding
expressions, computes a score, reads current and historical scores, and queries
the landlord summaries. It completes without an exception before printing the
confirmation message.

## Distribution check

The root `.gitattributes` marks `workbench/` as `export-ignore`. Verify the
actual Composer artifact, not only the attribute:

```bash
composer archive --format=zip --file=build/customer-health-dist
unzip -Z1 build/customer-health-dist.zip | grep '^workbench/'
```

The final command must print nothing and exit with status `1`, meaning no
workbench path is present in the distribution archive.
