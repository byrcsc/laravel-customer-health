<?php

declare(strict_types=1);

use ByRcsc\LaravelCustomerHealth\Support\TableNames;
use ByRcsc\LaravelCustomerHealth\Tests\TestCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(TestCase::class)->in(__DIR__);

function runCustomerHealthStorageMigrations(): void
{
    foreach (['create_customer_health_events_table', 'create_customer_health_milestones_table'] as $migration) {
        /** @var Migration $instance */
        $instance = require __DIR__."/../database/migrations/{$migration}.php.stub";
        $instance->up();
    }
}

function createTrackableFixtures(string $connection = 'testing'): void
{
    Schema::connection($connection)->create('test_subjects', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    Schema::connection($connection)->create('test_actors', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
}

function dropCustomerHealthStorage(string $connection): void
{
    $schema = Schema::connection($connection);

    foreach ([
        'milestone_dispatches',
        'tenant_product_milestones',
        TableNames::default('milestones'),
        'tenant_product_events',
        TableNames::default('events'),
        'test_actors',
        'test_subjects',
    ] as $table) {
        $schema->dropIfExists($table);
    }
}

/** @return Closure(): void */
function configureCustomerHealthTenantDatabase(): Closure
{
    /** @var array<string, mixed> $connection */
    $connection = config('database.connections.testing');
    $driver = $connection['driver'] ?? 'sqlite';

    if ($driver === 'sqlite') {
        config()->set('database.connections.tenant', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        return function (): void {
            DB::purge('tenant');
            config()->set('database.connections.tenant');
        };
    }

    $database = 'customer_health_switch_test';
    $databaseConnection = DB::connection('testing');

    if ($driver === 'mysql') {
        $databaseConnection->unprepared("DROP DATABASE IF EXISTS `{$database}`");
        $databaseConnection->unprepared("CREATE DATABASE `{$database}`");
    } else {
        $databaseConnection->statement(
            'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()',
            [$database],
        );
        $databaseConnection->unprepared("DROP DATABASE IF EXISTS \"{$database}\"");
        $databaseConnection->unprepared("CREATE DATABASE \"{$database}\"");
    }

    $connection['database'] = $database;
    config()->set('database.connections.tenant', $connection);

    return function () use ($database, $databaseConnection, $driver): void {
        DB::purge('tenant');

        if ($driver === 'mysql') {
            $databaseConnection->unprepared("DROP DATABASE IF EXISTS `{$database}`");
        } else {
            $databaseConnection->statement(
                'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()',
                [$database],
            );
            $databaseConnection->unprepared("DROP DATABASE IF EXISTS \"{$database}\"");
        }

        config()->set('database.connections.tenant');
    };
}
