# laravel-customer-health — v1 scope (plan of record)

Agreed 2026-08-02 after a full decision-tree review. Each decision below is
settled; reopening one means reopening its dependents.

## Identity

Business-event tracking, feature adoption, onboarding progress, and
declarative customer health scores in the `byrcsc/*` family. Spatie skeleton,
`workbench/` demo app, Laravel 12/13, PHP 8.3/8.4, PHPStan max, tested on
MySQL and PostgreSQL. Namespace `ByRcsc\LaravelCustomerHealth`, facade
`CustomerHealth`, config `customer-health.php`.

**The bet:** the edge feature is the health score shipped as a *primitive*,
not a product. The application declares its own events, signals, weights, and
thresholds; the package computes, stores history, and emits lifecycle events.
Every deferred feature (check-ins, dashboards, feedback) stays possible for
users through those events and the query API without the package shipping it.

**Standalone + composable.** Zero dependency on sibling packages. All models
are ordinary Eloquent models.

## Domain model

- **Subject (polymorphic, load-bearing):** any Eloquent model implementing
  `Trackable` is the entity adoption, inactivity, and health attach to. Not a
  hardcoded account/user hierarchy, not a single configured model.
- **Actor:** every event optionally carries the user who did it, as a second
  morph. "Which teammate did it" must never be lost.

## Event tracking

- **Registered class definitions, not free-form strings.** An event is a PHP
  class extending `ProductEvent` declaring `$feature` and `$milestone`
  (and optionally `$name`; default derived from the class). The declarations
  ARE the business-aware layer: adoption and onboarding queries derive from
  them. Registration lives in `config('customer-health.events')`; tracking an
  unregistered event class throws.
- **Write path: sync insert by default, queued opt-in** via
  `config('customer-health.queue')`. Business events are rare compared to
  page views; sync is the right default. The queued job must be tenant-aware
  under spatie/laravel-multitenancy.
- **First occurrences are written to a permanent milestones table at track
  time**, in the same transaction as the event insert, race-safe via a unique
  index (`insertOrIgnore`), never by application-level existence checks.
- Recording fires `ProductEventRecorded` always and `MilestoneReached` on
  first occurrence only.

## Adoption and inactivity

- Query API: `hasAdopted`, `featureUsage` (first/last use, counts, distinct
  actors), `lastSeen`, `inactive(days:)`. Adoption and milestone answers read
  the milestones table, so they are prune-safe by construction.
- **Inactivity is a query, not a stored state.** A customer going quiet emits
  no event; the scheduled recompute is what notices silence, through signals
  like `RecentActivity`. State transitions (including "went quiet enough to
  be at-risk") are expressed as health score states.
- **Window semantics: UTC, evaluated at compute time.** One rule, documented,
  deterministic, testable.

## Onboarding

- **Thin ordered checklist over milestones.** A `Checklist` class lists
  milestone event classes in order. The package derives per-subject progress
  (n of m, percent, current step, `stalledSince()`), fires
  `OnboardingStepCompleted` and `OnboardingCompleted`. Completion is recorded
  as a synthetic milestone row so it fires exactly once. No dependencies,
  branching, or per-role paths in v1.

## Health scores

- **Weighted signal classes.** A `HealthScore` class returns an array of
  signal instances, each answering 0–100 for a subject with a weight
  (normalized; weights need not sum to 100), plus `states()` mapping
  thresholds to named states (`at_risk`, `watch`, `healthy` — names are the
  app's choice).
- Built-in signals: `RecentActivity`, `FeatureAdopted`, `FeatureActivity`,
  `DistinctActors`, `OnboardingProgress`. Custom signals implement the
  `Signal` contract and may query anything (billing, tickets).
- Compute stores a **history row with the per-signal breakdown** (why the
  score is what it is), fires `HealthScoreComputed` every time and
  `HealthStateChanged(from, to)` on threshold crossings, detected by
  comparing against the previous history row.
- **No default weights or thresholds ship.** Opinions are the app's.

## Compute cadence and tenancy

- **Scheduled recompute** via `customer-health:recompute`; the app schedules
  it (daily default in docs). No event-triggered recompute in v1 (v1.x
  candidate). Lazy read-time compute is rejected: state-change events must
  fire even when nobody is looking.
- **Tenancy-agnostic core:** all writes go to a configurable connection
  (default: the current default connection), which is what
  database-per-tenant packages switch. Compatibility with
  **spatie/laravel-multitenancy (database-per-tenant) is a tested claim**:
  CI boots it with two tenant databases and exercises track → recompute →
  landlord sync. The first consuming app is the spec.
- **Landlord summary sync:** raw events stay per-tenant; recompute upserts a
  compact current row (tenant, subject morph, score key, value, state,
  computed_at) into a summaries table on a configurable connection. "Show me
  every at-risk customer" is one query against that table, in both
  single-database and multi-database apps (tenant is null in single-DB).

## Storage and retention

Tables (names configurable via a `TableNames` support class, mirroring
sibling packages):

- `customer_health_events` — raw stream: subject morph, actor morph
  (nullable), name, feature, properties JSON, occurred_at. Prunable.
- `customer_health_milestones` — permanent first occurrences: subject morph,
  name, actor morph, occurred_at, **unique (subject, name)**.
- `customer_health_scores` — score history: subject morph, score key, value,
  state, breakdown JSON, computed_at.
- `customer_health_summaries` — current value/state per subject per score,
  on the summary connection, **unique (tenant, subject, score key)**.

Retention: events model is `MassPrunable`;
`config('customer-health.retention_days')` defaults to null (keep forever).
**Pruning never changes adoption, milestone, or onboarding answers** — those
read the milestones table.

## Privacy

Event properties will contain personal data. v1 ships
`CustomerHealth::purge($subject)` and `customer-health:purge` removing a
subject's events, milestones, score history, and summaries. SECURITY.md
documents the trust boundary: the package stores what the app passes it;
the app is the controller.

## Public API (surface-test enforced)

Base classes (`ProductEvent`, `Checklist`, `HealthScore`), the `Signal`
contract, built-in signals, the `Trackable` interface + trait, the
`CustomerHealth` facade and its query methods, both artisan commands, the
config file keys, and **all fired events** (`ProductEventRecorded`,
`MilestoneReached`, `OnboardingStepCompleted`, `OnboardingCompleted`,
`HealthScoreComputed`, `HealthStateChanged`) — the events are exactly what
apps build check-ins on, so they are public API from day one. A surface test
imports every public class; the README's API list must match it.

## Out of scope (v1)

- Page-view / session analytics.
- Automation engine (check-in scheduler, playbooks, action rules) — a
  documented listener recipe instead.
- Dashboard UI.
- Feedback / survey capture (NPS, CSAT).
- Metric warehouse: arbitrary KPI counters, time-series rollups, charting
  endpoints.
- Event-triggered instant recompute (v1.x candidate).
- First-class adapters for tenancy packages beyond the tested
  spatie/laravel-multitenancy compatibility.

## Risks — do not let these fall out

- **Milestone uniqueness is a DB constraint,** not an application check; two
  concurrent "first" events are a guaranteed race.
- **The compatibility table can only claim what CI runs:** MySQL,
  PostgreSQL, and the spatie multi-DB scenario all need real jobs.
- **The workbench demo is the multi-DB app,** because that is the hardest
  real integration seam; a single-DB demo leaves the riskiest path untested.
- **Inactivity depends on the scheduler.** The install docs must make the
  schedule step impossible to miss, or "at-risk detection doesn't work"
  becomes the top issue.
- **UTC window rule** is documented once and used everywhere; drifting
  per-signal timezone logic makes scores nondeterministic.

## Build order

Tickets live flat in `issues/` (see `issues/00-index.md` for the dependency
table), grouped and buildable top to bottom:

- **A Foundation:** 01 foundations and scaffold, 02 schema/models/config,
  03 event definitions and track(), 04 queued writes.
- **B Reading the data:** 05 adoption and inactivity queries,
  06 onboarding checklists.
- **C Health scoring:** 07 health scores and signals, 08 recompute command,
  09 landlord summaries.
- **D Tenancy and lifecycle:** 10 spatie/laravel-multitenancy
  compatibility, 11 retention and purge.
- **E Release readiness:** 12 surface test, 13 workbench demo,
  14 docs and v1.0.0 release.
