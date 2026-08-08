<?php

declare(strict_types=1);

use ByRcsc\LaravelCustomerHealth\CustomerHealthServiceProvider;
use ByRcsc\LaravelCustomerHealth\Support\TableNames;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

it('loads the config defaults', function (): void {
    expect(config('customer-health.table_names'))->toBe([
        'events' => 'customer_health_events',
        'milestones' => 'customer_health_milestones',
        'scores' => 'customer_health_scores',
        'summaries' => 'customer_health_summaries',
    ])
        ->and(config('customer-health.connection'))->toBeNull()
        ->and(config('customer-health.summary_connection'))->toBeNull()
        ->and(config('customer-health.scores'))->toBe([]);
});

// The shipped config and TableNames::DEFAULTS name the same tables on
// purpose; this is what stops the two maps drifting apart.
it('ships config defaults that match TableNames', function (): void {
    expect(config('customer-health.table_names'))->toBe(TableNames::defaults());
});

it('publishes the config under the customer-health-config tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(CustomerHealthServiceProvider::class, 'customer-health-config');

    expect($paths)->not->toBeEmpty();

    foreach ($paths as $source => $destination) {
        expect($source)->toEndWith('config/customer-health.php')
            ->and($destination)->toEndWith('config/customer-health.php');
    }
});

// Trivial on SQLite, load-bearing in CI: it is the one assertion that proves
// the MySQL and PostgreSQL services in the test matrix are actually wired up.
it('connects to the configured database', function (): void {
    expect(DB::connection()->select('select 1 as one'))->toHaveCount(1);
});
