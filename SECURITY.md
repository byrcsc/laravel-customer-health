# Security Policy

## Reporting a vulnerability

Please report privately, not in a public issue. Use GitHub's
[private vulnerability reporting](https://github.com/byrcsc/laravel-customer-health/security/advisories/new)
on this repository; it opens a channel visible only to the maintainers.

Include the package version, the Laravel and PHP versions, the database driver,
and enough detail to reproduce. If it helps, a failing test is the clearest
possible report. **Do not include real customer data in the report.**

You can expect an acknowledgement within a week. Because this is a
single-maintainer package, please do not expect a same-day response; if the
issue is being actively exploited, say so in the title.

## Supported versions

Security fixes are released for the latest package version and are not
backported, and neither are ordinary bug fixes. Only the current major receives
either; when a new major is released, the previous one stops receiving fixes.
Keep your dependency constraint current.

## Trust boundary and data control

This package records what customers do and scores them on it. That makes it a
data-retention component more than an access-control one, so the boundary is
mostly about where customer data goes and how completely it can be removed.
The consuming application is the data controller: the package stores the
subject, actor, timestamps, and properties the application passes to it.
Properties may contain personal data, and the application is responsible for
lawful collection, minimization, access control, retention, and coordinating
deletion in backups or downstream systems. `CustomerHealth::purge($subject)`
and `customer-health:purge` are the package's erasure mechanisms.

It **does**:

- write to configurable connections, so events, milestones, and score history
  can live in a per-tenant database while summaries live on the landlord
  connection. Nothing forces customer data onto a shared connection;
- stamp summaries with the current tenant, so a landlord-side query cannot
  silently mix one tenant's customers into another's results;
- support retention limits on raw events through `retention_days` and
  `model:prune`, without changing the answers that milestones and scores
  already recorded;
- support complete erasure of one subject through `customer-health:purge` or
  `CustomerHealth::purge($subject)`, which removes that subject's events,
  milestones, score history, and summaries.

It **does not**:

- authorize reads. The query API answers whatever it is asked. There is no
  policy, no per-user scoping, and no check that the caller may see the
  subject. Exposing scores, adoption, or onboarding progress to a user is the
  application's decision, and an unscoped query in a multi-tenant application
  will return other tenants' subjects.
- validate what you put in a payload. Event payloads are stored as given. If
  the application tracks a password, a token, or a full name in one, the
  package stores it, includes it in whatever the query API returns, and keeps
  it for as long as retention allows. Deciding what is safe to track is the
  application's, and it is the single most likely way to leak data with this
  package.
- redact or minimize anything. There is no field-level encryption, no
  pseudonymization of the subject or actor reference, and no allowlist of
  payload keys.
- erase a subject from anywhere but its own tables. `purge` does not reach
  queued jobs that have not run, database backups, read replicas, log files
  that recorded the track call, or any downstream system a listener forwarded
  the event to. Treat it as the first step of a deletion request, not the
  whole of one.
- guarantee erasure completed if writes are queued. With `'queue' => true`, a
  purge can run before an in-flight tracking job lands, which writes the
  subject back. Drain the queue first.
- protect stored rows from modification. These are ordinary Eloquent models.
  A score in history can be edited or deleted by anything with database
  access, and there is no tamper evidence. Score history is a record, not an
  audit trail.
- make a health score meaningful. The package computes the weighted total of
  the signals declared for it and nothing more. It has no view on whether
  those signals measure anything real.

## Reporting something that is documented

The list above is the intended boundary, and the README's **Multi-tenancy**,
**Retention and privacy**, and **Out of scope** sections state it in full. A
report that a documented limitation exists is not a vulnerability, but a report
that the package does not actually hold a line it claims to hold very much is.
The clearest examples of the latter would be a query returning subjects from
another tenant, or `purge` leaving a subject's rows behind in a table it claims
to clear. When in doubt, report it.
