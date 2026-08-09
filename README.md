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

Tested on MySQL and PostgreSQL, including database-per-tenant applications
using `spatie/laravel-multitenancy`.

## Installation

Install the package, publish its configuration and migrations, then migrate:

```bash
composer require byrcsc/laravel-customer-health
php artisan vendor:publish --tag="customer-health-config"
php artisan vendor:publish --tag="customer-health-migrations"
php artisan migrate
```

Schedule score recomputation. Inactivity produces no event, so the scheduled
command is what notices customers going quiet.

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('customer-health:recompute')->daily();
```

## Concepts

A **subject** is the Eloquent model whose health you track, such as a team or
organization. A product event records a business action against that subject,
with an optional user as its **actor**.

A milestone preserves the first occurrence of an event for adoption and
onboarding. Health scores combine weighted signals, store explainable history,
and update a compact current summary for each subject.

## Quick start

Make the customer model trackable:

```php
use ByRcsc\LaravelCustomerHealth\Concerns\TracksCustomerHealth;
use ByRcsc\LaravelCustomerHealth\Contracts\Trackable;
use Illuminate\Database\Eloquent\Model;

final class Team extends Model implements Trackable
{
    use TracksCustomerHealth;
}
```

Declare a business event and mark its first occurrence as a milestone:

```php
use ByRcsc\LaravelCustomerHealth\Events\ProductEvent;

final class WorkflowCreated extends ProductEvent
{
    public static string $feature = 'workflows';

    public static bool $milestone = true;
}
```

Register the event in `config/customer-health.php`:

```php
'events' => [WorkflowCreated::class],
```

Track the event after the business operation succeeds, then query adoption:

```php
use ByRcsc\LaravelCustomerHealth\Facades\CustomerHealth;

CustomerHealth::track(new WorkflowCreated($team, actor: $user));

CustomerHealth::hasAdopted($team, 'workflows'); // true
```

## Documentation

The [versioned documentation][documentation] contains the complete setup,
guides, operations advice, and API reference:

- [Installation and setup][installation]
- [Full quick start][quick-start]
- [Production operations][production-operations]
- [Public API reference][public-api]

## Out of scope

Things this package will not do, so you can build on what it does do:

- Page-view or session analytics.
- Automation engine: check-in scheduler, playbooks, or action rules.
- Dashboard UI.
- Feedback or survey capture, including NPS and CSAT.
- Metric warehouse: arbitrary KPI counters, time-series rollups, or charting
  endpoints.
- Event-triggered instant recomputation.
- First-class adapters for tenancy packages beyond the tested
  `spatie/laravel-multitenancy` compatibility.

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

[documentation]: https://docs.rcsc.dev/laravel-customer-health/v1/introduction
[installation]: https://docs.rcsc.dev/laravel-customer-health/v1/installation
[production-operations]: https://docs.rcsc.dev/laravel-customer-health/v1/production-operations
[public-api]: https://docs.rcsc.dev/laravel-customer-health/v1/public-api
[quick-start]: https://docs.rcsc.dev/laravel-customer-health/v1/quick-start
