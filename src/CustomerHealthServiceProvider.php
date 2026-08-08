<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth;

use ByRcsc\LaravelCustomerHealth\Commands\PurgeCustomerHealthCommand;
use ByRcsc\LaravelCustomerHealth\Commands\RecomputeHealthScoresCommand;
use ByRcsc\LaravelCustomerHealth\Events\ProductEvent;
use ByRcsc\LaravelCustomerHealth\Onboarding\Checklist;
use ByRcsc\LaravelCustomerHealth\Registry\ChecklistRegistry;
use ByRcsc\LaravelCustomerHealth\Registry\EventRegistry;
use ByRcsc\LaravelCustomerHealth\Registry\HealthScoreRegistry;
use ByRcsc\LaravelCustomerHealth\Scoring\HealthScore;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class CustomerHealthServiceProvider extends PackageServiceProvider
{
    public function packageRegistered(): void
    {
        $this->app->singleton(EventRegistry::class, function (Application $app): EventRegistry {
            $config = $app->make(Repository::class);

            /** @var mixed $configured */
            $configured = $config->get('customer-health.events', []);

            /** @var list<class-string<ProductEvent>> $eventClasses */
            $eventClasses = is_array($configured) ? array_values($configured) : [];

            return new EventRegistry($eventClasses);
        });

        $this->app->singleton(CustomerHealthManager::class);
        $this->app->singleton(ChecklistRegistry::class, function (Application $app): ChecklistRegistry {
            $config = $app->make(Repository::class);
            $configured = $config->get('customer-health.checklists', []);
            /** @var list<class-string<Checklist>> $classes */
            $classes = is_array($configured) ? array_values($configured) : [];

            return new ChecklistRegistry($classes, $app->make(EventRegistry::class));
        });
        $this->app->singleton(HealthScoreRegistry::class, function (Application $app): HealthScoreRegistry {
            $config = $app->make(Repository::class);
            $configured = $config->get('customer-health.scores', []);
            /** @var list<class-string<HealthScore>> $classes */
            $classes = is_array($configured) ? array_values($configured) : [];

            return new HealthScoreRegistry($classes);
        });
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-customer-health')
            ->hasConfigFile()
            ->hasMigrations([
                'create_customer_health_events_table',
                'create_customer_health_milestones_table',
                'create_customer_health_scores_table',
                'create_customer_health_summaries_table',
            ])
            ->hasCommands([
                RecomputeHealthScoresCommand::class,
                PurgeCustomerHealthCommand::class,
            ]);
    }
}
