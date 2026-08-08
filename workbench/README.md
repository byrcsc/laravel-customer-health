# Workbench

The demo app for driving `byrcsc/laravel-customer-health` by hand. Build it
from clean with:

```bash
composer build
```

This creates the SQLite database and migrates.

The workbench is configured as a database-per-tenant Spatie v4 application:

- `landlord` is the default SQLite connection and stores tenants and customer
  health summaries.
- `tenant` starts with a null database; `SwitchTenantDatabaseTask` selects the
  current tenant's SQLite file.
- `Workbench\App\Models\Tenant` is the landlord tenant model.
- `Workbench\App\Models\Team` is a trackable model on the tenant connection.
- package events, milestones, and scores belong in the tenant migrations path;
  the summaries migration belongs in the landlord migrations path.

The executable two-tenant flow lives in
`tests/Compatibility/SpatieMultitenancyTest.php` and runs against MySQL in its
own CI job. The seeded interactive demo lands with issue E2.
