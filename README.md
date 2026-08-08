# Laravel Customer Health

[![Latest Version on Packagist](https://img.shields.io/packagist/v/byrcsc/laravel-customer-health.svg?style=flat-square)](https://packagist.org/packages/byrcsc/laravel-customer-health)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/byrcsc/laravel-customer-health/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/byrcsc/laravel-customer-health/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub PHPStan Action Status](https://img.shields.io/github/actions/workflow/status/byrcsc/laravel-customer-health/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/byrcsc/laravel-customer-health/actions?query=workflow%3APHPStan+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/byrcsc/laravel-customer-health.svg?style=flat-square)](https://packagist.org/packages/byrcsc/laravel-customer-health)

Business-event tracking, feature adoption, onboarding progress, and
declarative customer health scores for Laravel applications. Instead of
telling you that someone visited `/dashboard`, it tells you that a customer
created their first workflow, invited a teammate, completed onboarding, or
went quiet on a critical feature.

The package provides the tracking and scoring engine. Your application keeps
ownership of its UI, users, teams, tenancy, and what "healthy" means: you
declare the events, the signals, and the weights; the package computes,
stores history, and fires plain Laravel events when a customer's state
changes.

| Laravel | Tested PHP versions |
|---|---|
| 12.x | 8.3, 8.4 |
| 13.x | 8.3, 8.4 |

Tested on MySQL and PostgreSQL, including a database-per-tenant setup with
`spatie/laravel-multitenancy`.

## Installation

Install the package and publish its migrations:

```bash
composer require byrcsc/laravel-customer-health
php artisan vendor:publish --tag="customer-health-migrations"
php artisan migrate
```

Publish the configuration before migrating when you need custom table names
or custom connections:

```bash
php artisan vendor:publish --tag="customer-health-config"
```

Schedule the recompute command. Health scores and inactivity detection depend
on it: a customer going quiet produces no event, so only a scheduled pass can
notice the silence.

```php
// routes/console.php
Schedule::command('customer-health:recompute')->daily();
```

## Concepts

- A **subject** is the entity whose health you care about: a team, an
  organization, a user. Any Eloquent model becomes one by implementing the
  `Trackable` interface. Every recorded event also carries an optional
  **actor**, the user who did the thing.
- A **product event** is a PHP class the application declares: the event
  name, the **feature** it belongs to, and whether its first occurrence is a
  **milestone**. Declarations are what make queries business-aware.
- First occurrences are written to a permanent **milestones** table at track
  time. Raw events can be pruned later without ever changing the answer to
  "has this customer adopted feature X".
- An **onboarding checklist** is an ordered list of milestone events. The
  package derives per-subject progress and fires step and completion events.
- A **health score** is a class composing weighted **signals**, each
  answering 0 to 100 for a subject. The package computes the weighted total
  on schedule, stores history with a per-signal breakdown, maps ranges to
  **states** such as `healthy` or `at_risk`, and fires `HealthStateChanged`
  when a subject crosses a threshold.
- Current score and state per subject are synced to a compact **summaries**
  table on a configurable connection, so "show me every at-risk customer" is
  one query even when raw events live in per-tenant databases.

## Public API

The entries below are the compatibility surface for `1.x`. Method signatures,
event properties, command arguments and options, and config keys are locked by
the test suite. Model records are readable; write through the facade so
milestones, score history, summaries, and fired events stay consistent.

<!-- public-api:start -->
- `Events\ProductEvent`
- `Onboarding\Checklist`
- `Scoring\HealthScore`
- `Contracts\Trackable`
- `Scoring\Signal`
- `Scoring\WindowedSignal`
- `Contracts\TenantResolver`
- `Tenancy\SpatieTenantResolver`
- `Concerns\TracksCustomerHealth`
- `Scoring\Signals\RecentActivity`
- `Scoring\Signals\FeatureAdopted`
- `Scoring\Signals\FeatureActivity`
- `Scoring\Signals\DistinctActors`
- `Scoring\Signals\OnboardingProgress`
- `Facades\CustomerHealth::track`
- `Facades\CustomerHealth::hasAdopted`
- `Facades\CustomerHealth::featureUsage`
- `Facades\CustomerHealth::lastSeen`
- `Facades\CustomerHealth::inactive`
- `Facades\CustomerHealth::features`
- `Facades\CustomerHealth::onboarding`
- `Facades\CustomerHealth::stalledInOnboarding`
- `Facades\CustomerHealth::compute`
- `Facades\CustomerHealth::score`
- `Facades\CustomerHealth::scoreHistory`
- `Facades\CustomerHealth::inState`
- `Facades\CustomerHealth::summaries`
- `Facades\CustomerHealth::purge`
- `ValueObjects\FeatureUsage`
- `ValueObjects\Progress`
- `ValueObjects\ScoreResult`
- `Events\ProductEventRecorded::$record`
- `Events\MilestoneReached::$milestone`
- `Events\OnboardingStepCompleted::$milestone`
- `Events\OnboardingStepCompleted::$checklist`
- `Events\OnboardingStepCompleted::$step`
- `Events\OnboardingCompleted::$milestone`
- `Events\OnboardingCompleted::$checklist`
- `Events\HealthScoreComputed::$record`
- `Events\HealthScoreComputed::$result`
- `Events\HealthStateChanged::$record`
- `Events\HealthStateChanged::$from`
- `Events\HealthStateChanged::$to`
- `Exceptions\UnregisteredEventException`
- `customer-health:recompute {--score=} {--subject=} {--chunk=500}`
- `customer-health:purge {subject_type} {subject_id} {--tenant=}`
- `customer-health.table_names.events`
- `customer-health.table_names.milestones`
- `customer-health.table_names.scores`
- `customer-health.table_names.summaries`
- `customer-health.connection`
- `customer-health.summary_connection`
- `customer-health.events`
- `customer-health.checklists`
- `customer-health.scores`
- `customer-health.tenant_resolver`
- `customer-health.retention_days`
- `customer-health.queue`
- `customer-health.queue_connection`
- `customer-health.queue_name`
- `Models\ProductEventRecord`
- `Models\Milestone`
- `Models\HealthScoreRecord`
- `Models\HealthSummary`
<!-- public-api:end -->

## Quick start

Declare a product event where your domain already knows it happened:

```php
use ByRcsc\LaravelCustomerHealth\Events\ProductEvent;

class WorkflowCreated extends ProductEvent
{
    public static string $feature = 'workflows';

    public static bool $milestone = true;
}
```

Record it:

```php
use ByRcsc\LaravelCustomerHealth\Facades\CustomerHealth;

CustomerHealth::track(new WorkflowCreated($team, actor: $user));
```

Ask business questions:

```php
CustomerHealth::hasAdopted($team, 'workflows');        // bool, prune-safe
CustomerHealth::featureUsage('workflows')->for($team); // first/last use, counts, distinct actors
CustomerHealth::lastSeen($team);                       // ?CarbonImmutable
CustomerHealth::inactive(days: 14)->get();             // subjects gone quiet
```

Define onboarding as an ordered checklist of milestones:

```php
use ByRcsc\LaravelCustomerHealth\Onboarding\Checklist;

class Onboarding extends Checklist
{
    public function steps(): array
    {
        return [
            AccountCreated::class,
            WorkflowCreated::class,
            TeammateInvited::class,
        ];
    }
}

CustomerHealth::onboarding($team)->progress();     // 2 of 3
CustomerHealth::onboarding($team)->stalledSince(); // ?CarbonImmutable
```

Declare what "healthy" means for your product:

```php
use ByRcsc\LaravelCustomerHealth\Scoring\HealthScore;
use ByRcsc\LaravelCustomerHealth\Scoring\Signals\DistinctActors;
use ByRcsc\LaravelCustomerHealth\Scoring\Signals\FeatureAdopted;
use ByRcsc\LaravelCustomerHealth\Scoring\Signals\OnboardingProgress;
use ByRcsc\LaravelCustomerHealth\Scoring\Signals\RecentActivity;

class CustomerHealthScore extends HealthScore
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
```

Register your declarations in `config/customer-health.php`:

```php
'events' => [
    AccountCreated::class,
    WorkflowCreated::class,
    TeammateInvited::class,
],

'checklists' => [Onboarding::class],

'scores' => [CustomerHealthScore::class],
```

React to state changes with a plain listener. This is the whole "check-in"
story: the package tells you the moment a customer becomes at-risk or hits a
milestone, and your application decides what a check-in looks like.

```php
use ByRcsc\LaravelCustomerHealth\Events\HealthStateChanged;

class NotifyCustomerSuccess
{
    public function handle(HealthStateChanged $event): void
    {
        if ($event->to === 'at_risk') {
            // notify the CSM, open a ticket, send the Slack ping
        }
    }
}
```

Read the results anywhere:

```php
CustomerHealth::compute($team);          // evaluate now and append history
CustomerHealth::score($team);            // current value, state, per-signal breakdown
CustomerHealth::scoreHistory($team);     // how it moved
CustomerHealth::inState('at_risk')->get(); // one query across all customers
```

`RecentActivity`, `FeatureActivity`, and `DistinctActors` are presence
signals: they return 100 when matching activity exists inside their inclusive
UTC window and 0 otherwise. Use a custom `Signal` when a count-based target is
part of your product's health definition.

## Multi-tenancy

The core is tenancy-agnostic: events, milestones, and scores are written to
a configurable connection (the current default connection by default), which
is exactly what database-per-tenant packages switch for you.

For `spatie/laravel-multitenancy` v4 with one database per tenant, install the
optional integration and publish Spatie's configuration:

```bash
composer require spatie/laravel-multitenancy
php artisan vendor:publish --tag="multitenancy-config"
```

Configure separate named connections. The `tenant` connection starts without
a database because Spatie fills it from the current tenant's `database`
attribute:

```php
// config/database.php
'connections' => [
    'landlord' => [/* driver, host, database, credentials, ... */],
    'tenant' => [/* same server settings, but 'database' => null */],
],
```

```php
// config/multitenancy.php
'tenant_model' => App\Models\Tenant::class,
'switch_tenant_tasks' => [
    Spatie\Multitenancy\Tasks\SwitchTenantDatabaseTask::class,
],
'tenant_database_connection_name' => 'tenant',
'landlord_database_connection_name' => 'landlord',
'queues_are_tenant_aware_by_default' => true,
```

Point package history at the switched connection and compact summaries at the
landlord. The optional resolver is safe to reference only when Spatie is
installed:

```php
// config/customer-health.php
'connection' => 'tenant',
'summary_connection' => 'landlord',
'tenant_resolver' => ByRcsc\LaravelCustomerHealth\Tenancy\SpatieTenantResolver::class,
```

Then split the published package migrations by storage role:

- Include the package migrations in your tenant migrations path; the
  events, milestones, and scores migrations run for every tenant. Run only the
  summaries migration on the landlord connection.
- Run recomputation per tenant: `php artisan tenants:artisan
  "customer-health:recompute"`.
- Summaries carry the current tenant, so the landlord can answer "which
  customers are at risk" across every tenant database with one query.

The repository workbench contains the exact connection, multitenancy, package,
tenant-model, and tenant-subject configuration shown above. CI exercises the
full flow against MySQL with two tenant databases. Single-database apps need
none of it: leave the connections and resolver at their defaults.

## Queued writes

Tracking is a direct insert by default, which suits business-level events.
High-traffic apps can switch to queued writes:

```php
'queue' => true,
```

The default is synchronous, so the quick start records its first event
without a queue worker. Once queued writes are enabled, a running worker is
required. The queue connection and name can be selected with
`queue_connection` and `queue_name`; null uses the application's defaults.

Queue retries intentionally write another raw event because the package
cannot know whether the business event itself was delivered twice. Milestone
rows remain exactly once through their database unique constraint.

Spatie v4 makes queued jobs tenant-aware by default. Keep
`queues_are_tenant_aware_by_default` enabled. The compatibility test dispatches
through a central database queue, clears the current tenant, and runs a worker
to prove each write lands in the originating tenant database.

## Troubleshooting

### Scores are never computed

Confirm `customer-health:recompute` is scheduled and that Laravel's scheduler
is running. Tracking an event does not compute a score in v1; the scheduled
command is deliberately what notices both new activity and customers going
quiet. Run `php artisan customer-health:recompute` manually to verify the
registered score definitions and inspect any per-subject failures.

## Retention and privacy

Raw events can be pruned without losing lifetime facts. Milestones, scores,
and summaries are permanent; adoption and onboarding answers never change
because old events were deleted.

```php
'retention_days' => 365, // null (default) keeps raw events forever
```

```php
use ByRcsc\LaravelCustomerHealth\Models\ProductEventRecord;

Schedule::command('model:prune', [
    '--model' => [ProductEventRecord::class],
])->daily();
```

Retention uses each event's UTC `occurred_at` value. Keep `retention_days` at
least as long as the longest activity window in any registered health score;
`customer-health:recompute` warns when a built-in or custom `WindowedSignal`
needs more history than retention preserves. Milestone-based adoption and
onboarding answers remain stable after pruning, while `RecentActivity`,
`FeatureActivity`, `DistinctActors`, and other raw-event windows may change.

To erase a customer entirely, for offboarding or a data-deletion request:

```bash
php artisan customer-health:purge "App\Models\Team" 42
```

or `CustomerHealth::purge($team)` from code. Both remove the subject's
events, milestones, score history, and summaries. In a database-per-tenant
application, run the command inside Spatie's tenant context:

```bash
php artisan tenants:artisan \
  'customer-health:purge "App\\Models\\Team" 42' \
  --tenant=7
```

The outer `--tenant` selects the tenant database. If the active integration
cannot resolve the summary tenant id, the inner command also accepts
`--tenant=7` as a landlord-summary match override. Purges are transactional on
each connection; no database abstraction can make two separate connections a
single distributed transaction, so rerun a failed purge before deleting the
application's subject model.

## Out of scope

Things this package will not do, so you can build on what it does do:

- **No page-view or session analytics.** Business events only. Use a web
  analytics tool alongside it.
- **No automation engine.** No built-in check-in scheduler, playbooks, or
  action rules. The package fires Laravel events; your listeners are the
  automation.
- **No dashboard UI.** The query API returns data; your application renders
  it.
- **No feedback or survey capture.** NPS and CSAT belong to another tool or
  a future sibling package.
- **No metric warehouse.** No arbitrary KPI counters, time-series rollups,
  or charting endpoints. Signals, adoption queries, and score history are
  the metrics surface.
- **No opinions about your customers.** The package ships signal building
  blocks, not default weights or thresholds. What "healthy" means is your
  declaration.

## Versioning

The package follows [semantic versioning](https://semver.org/spec/v2.0.0.html).

- Upgrading within `1.x` is safe. Nothing you use will break.
- Only a new major version, like `2.0.0`, can break your code.
- If the README or the documentation describes it, it is safe to build on.
  If they don't, treat it as internal and expect it to change.

Bug fixes go into the newest version only. To get a fix, upgrade to it.

## Questions and issues

- **Stuck, or have an idea?** Start a
  [discussion](https://github.com/byrcsc/laravel-customer-health/discussions).
  Usage questions and feature ideas both live there.
- **Found a bug you can reproduce?**
  [Open an issue](https://github.com/byrcsc/laravel-customer-health/issues).
  A failing test is the fastest way to a fix, and a short reproduction is the
  next best thing.
- **Found a security problem?** Please don't open a public issue. See
  [SECURITY.md](SECURITY.md) for how to report it privately.
- **Planning a pull request?** [CONTRIBUTING.md](CONTRIBUTING.md) covers the
  setup and the three checks it needs to pass.

This package is maintained by one person, so replies can take a while.
Everything gets read.

## License

MIT. See [LICENSE.md](LICENSE.md). Changelog in [CHANGELOG.md](CHANGELOG.md).
