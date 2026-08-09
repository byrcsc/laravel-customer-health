<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use ByRcsc\LaravelCustomerHealth\Events\HealthStateChanged;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Listeners\NotifyCustomerSuccess;

/**
 * Loads the demo's isolated config and registers its state-change listener.
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
        $this->app->make('events')->listen(
            HealthStateChanged::class,
            NotifyCustomerSuccess::class,
        );
    }
}
