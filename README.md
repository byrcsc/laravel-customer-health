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

For `spatie/laravel-multitenancy` with one database per tenant:

- Include the package migrations in your tenant migrations path; the
  summaries migration runs on the landlord connection.
- Run the recompute per tenant: `php artisan tenants:artisan
  customer-health:recompute`.
- Summaries carry the current tenant, so the landlord can answer "which
  customers are at risk" across every tenant database with one query.

This setup is exercised in CI. Single-database apps need none of it: leave
the connections at their defaults and everything lives in one database.

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

In a multi-tenant app, make the queue tenant-aware as described in the
spatie/laravel-multitenancy documentation so queued writes land in the right
tenant database.

## Retention and privacy

Raw events can be pruned without losing lifetime facts. Milestones, scores,
and summaries are permanent; adoption and onboarding answers never change
because old events were deleted.

```php
'retention_days' => 365, // null (default) keeps raw events forever
```

```php
Schedule::command('model:prune')->daily();
```

To erase a customer entirely, for offboarding or a data-deletion request:

```bash
php artisan customer-health:purge "App\Models\Team" 42
```

or `CustomerHealth::purge($team)` from code. Both remove the subject's
events, milestones, score history, and summaries.

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
