# Contributing

Thanks for helping. Clear, focused pull requests are easier to review and
maintain.

## Getting set up

```bash
git clone https://github.com/byrcsc/laravel-customer-health
cd laravel-customer-health
composer install
```

No database server and no `.env` are needed. The suite runs against an in-memory
SQLite database that Testbench sets up for you.

To reproduce a CI matrix failure locally, point the suite at a real engine with
`DB_DRIVER`:

```bash
DB_DRIVER=mysql composer test
DB_DRIVER=pgsql composer test
```

That path exists because the package writes across more than one connection,
and because SQLite does not tell the truth about index use on the event table,
which is the one table that grows without bound.

## The three checks

All three must be green before a pull request can be merged. CI runs them too,
but running them locally is faster than waiting.

```bash
composer test      # Pest, random order
composer analyse   # PHPStan, level max
composer format    # Pint, applies fixes
```

Two things worth knowing:

- **PHPStan runs at level max with no baseline.**
  If an error is genuinely a false positive, explain it in the pull request so
  we can find the right fix. Do not add `@phpstan-ignore`, a baseline entry, or
  a cast only to silence it.
- **Pint is the only style authority.** Run `composer format` before pushing.
  Avoid manual formatting that conflicts with its output.

The suite runs in random order and fails on warnings, risky tests, and an empty
suite. A test that passes only in a particular order is a bug in the test.

## The workbench

`workbench/` is a bootable demo application that installs the package the way a
real application would. It is where a change is driven by hand before it is
documented, and the only place the parts that need a running application meet
one: scheduled recomputation, queued writes, and pruning.

```bash
composer build    # set the demo up
composer clear    # tear it down again
```

See [workbench/README.md](workbench/README.md) for the demo loop. It is excluded
from the Composer dist archive and is not covered by CI, so it may drift. If you
change a public seam, run it and fix what broke.

## Where tests go

Group tests by behavior, not by class, and extend the group that owns the
behavior rather than adding a parallel suite for a class. `tests/ArchTest.php`
enforces strict types, string-backed enums, a single catchable exception root,
and no leftover debugging calls.

The package is in active construction, so the group layout is still forming.
When you add the first test for an area, name the directory after the behavior
(`Track/`, `Milestones/`, `Onboarding/`, `Score/`, `Prune/`, `Tenancy/`) rather
than after the class you happened to write.

## What a good change looks like

**Touching tracking.** First occurrences are written to the permanent
milestones table at track time, which is the reason raw events can be pruned
without changing the answer to "has this customer adopted feature X". Any
change that derives a milestone from the events table at read time breaks that
guarantee. It needs a test that prunes and then still gets the right answer.

**Touching scoring.** A score is a weighted composition of signals, each
answering 0 to 100. Score history is permanent and carries a per-signal
breakdown, so a change to how the total is computed changes the meaning of rows
already stored. Say so in the pull request and the changelog.

**Touching connections.** Events, milestones, and history are written to a
configurable connection; summaries go to their own. That split is what lets a
landlord query every tenant at once. A change that assumes one connection will
pass the single-database suite and break every multi-tenant install, so it
needs a test against the tenancy setup.

**Adding a signal.** Signals are building blocks, not defaults. A signal that
encodes an opinion about what healthy means belongs in an application, not
here.

**Fixing behavior.** Please include a test that fails before the change.

## Language

The package uses one canonical vocabulary: a **subject** is the entity whose
health is tracked and an **actor** is who did the thing. A **product event**
belongs to a **feature** and its first occurrence may be a **milestone**. An
**onboarding checklist** is an ordered list of milestone events. A **health
score** composes weighted **signals** and maps to a **state**. It does not use
page views, sessions, hits, KPIs, or metrics.

Documentation and docblocks state behavior, constraints, and tradeoffs directly.
Avoid conversational and anthropomorphic framing, and do not describe a score
as a prediction or a measurement of anything but the signals declared for it.

## Package scope

The following are deliberate boundaries rather than gaps, and the README's
**Out of scope** section is the full list. Explain any proposed change to them
in the pull request:

- **No page-view or session analytics.** Business events only.
- **No automation engine.** No check-in scheduler, playbooks, or action rules.
  The package fires Laravel events; listeners are the automation.
- **No dashboard UI.** The query API returns data; the application renders it.
- **No feedback or survey capture.** NPS and CSAT belong elsewhere.
- **No metric warehouse.** No arbitrary counters, time-series rollups, or
  charting endpoints.
- **No opinions about your customers.** No default weights or thresholds.
- **No compatibility aliases or deprecation shims.** A removed name is removed
  in the major release that removes it, and documented in the changelog.

## Commits and branches

Branch off `main` as `feat/…`, `fix/…`, `docs/…`, `refactor/…`, or `chore/…`,
and write [Conventional Commits](https://www.conventionalcommits.org/)
(`feat:`, `fix:`, `docs:`, `refactor:`, `chore:`). Mark breaking changes with
`!`, which is what decides whether a release is a major one.

## Security

Do not open a public issue for a vulnerability. See [SECURITY.md](SECURITY.md).
