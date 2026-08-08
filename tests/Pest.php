<?php

declare(strict_types=1);

use ByRcsc\LaravelCustomerHealth\Support\TableNames;
use ByRcsc\LaravelCustomerHealth\Tests\TestCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
