<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * The one file that stands in for an application's own service provider. Demo
 * wiring (trackable models, event declarations, listeners) lands here with
 * issue E2.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->app->environment('workbench')) {
            return;
        }

        foreach (['database', 'multitenancy', 'customer-health'] as $key) {
            $this->app->make('config')->set($key, require __DIR__."/../../config/{$key}.php");
        }
    }

    public function boot(): void
    {
        //
    }
}
