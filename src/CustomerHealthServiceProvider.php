<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class CustomerHealthServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        // Migrations register here when the schema lands (issue A2); the
        // `customer-health:recompute` and `customer-health:purge` commands
        // register here when they land (issues C2 and D2).
        $package
            ->name('laravel-customer-health')
            ->hasConfigFile()
            ->hasMigrations([
                'create_customer_health_events_table',
                'create_customer_health_milestones_table',
            ]);
    }
}
