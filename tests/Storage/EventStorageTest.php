<?php

declare(strict_types=1);

use ByRcsc\LaravelCustomerHealth\CustomerHealthServiceProvider;
use ByRcsc\LaravelCustomerHealth\Models\Milestone;
use ByRcsc\LaravelCustomerHealth\Models\ProductEventRecord;
use ByRcsc\LaravelCustomerHealth\Support\TableNames;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\TestActor;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\TestSubject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

function runCustomerHealthStorageMigrations(): void
{
    foreach (['create_customer_health_events_table', 'create_customer_health_milestones_table'] as $migration) {
        /** @var Migration $instance */
        $instance = require __DIR__."/../../database/migrations/{$migration}.php.stub";
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

beforeEach(function (): void {
    dropCustomerHealthStorage('testing');
});

afterEach(function (): void {
    dropCustomerHealthStorage('testing');

    if (config('database.connections.tenant') !== null) {
        dropCustomerHealthStorage('tenant');
    }
});

it('publishes and runs both storage migrations through Laravel', function (): void {
    $paths = ServiceProvider::pathsToPublish(CustomerHealthServiceProvider::class, 'customer-health-migrations');

    expect(array_keys($paths))->toHaveCount(2)
        ->and(array_keys($paths)[0])->toEndWith('.php.stub')
        ->and(array_keys($paths)[1])->toEndWith('.php.stub');

    try {
        expect(Artisan::call('vendor:publish', [
            '--provider' => CustomerHealthServiceProvider::class,
            '--tag' => 'customer-health-migrations',
            '--force' => true,
        ]))->toBe(0);

        foreach (array_values($paths) as $destination) {
            expect($destination)->toBeFile();
        }

        expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(0)
            ->and(Schema::hasTable(TableNames::events()))->toBeTrue()
            ->and(Schema::hasTable(TableNames::milestones()))->toBeTrue();
    } finally {
        File::delete(array_values($paths));
    }
});

it('creates the events and milestones schema with the required indexes', function (): void {
    runCustomerHealthStorageMigrations();

    $eventIndexes = collect(Schema::getIndexes(TableNames::events()))->pluck('name');
    $milestoneIndexes = collect(Schema::getIndexes(TableNames::milestones()))->pluck('name');

    expect(Schema::hasColumns(TableNames::events(), [
        'id', 'subject_type', 'subject_id', 'actor_type', 'actor_id', 'name',
        'feature', 'properties', 'occurred_at', 'created_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns(TableNames::milestones(), [
            'id', 'subject_type', 'subject_id', 'name', 'actor_type',
            'actor_id', 'occurred_at', 'created_at',
        ]))->toBeTrue()
        ->and($eventIndexes)->toContain(
            'ch_events_subject_name_time_idx',
            'ch_events_name_time_idx',
            'ch_events_feature_time_idx',
        )
        ->and($milestoneIndexes)->toContain('ch_milestones_subject_name_unique');
});

it('enforces one milestone per subject and event name', function (): void {
    runCustomerHealthStorageMigrations();

    $milestone = [
        'subject_type' => TestSubject::class,
        'subject_id' => '1',
        'name' => 'workflow_created',
        'occurred_at' => CarbonImmutable::parse('2026-08-08 00:00:00', 'UTC'),
        'created_at' => CarbonImmutable::parse('2026-08-08 00:00:00', 'UTC'),
    ];

    DB::table(TableNames::milestones())->insert($milestone);

    expect(fn (): bool => DB::table(TableNames::milestones())->insert($milestone))
        ->toThrow(QueryException::class);
});

it('round trips record data and polymorphic relations', function (): void {
    runCustomerHealthStorageMigrations();
    createTrackableFixtures();

    $subject = TestSubject::query()->create(['name' => 'Acme']);
    $actor = TestActor::query()->create(['name' => 'Taylor']);
    $occurredAt = CarbonImmutable::parse('2026-08-08 00:00:00', 'UTC');

    $event = ProductEventRecord::query()->create([
        'subject_type' => $subject->getMorphClass(),
        'subject_id' => (string) $subject->getKey(),
        'actor_type' => $actor->getMorphClass(),
        'actor_id' => (string) $actor->getKey(),
        'name' => 'workflow_created',
        'feature' => 'workflows',
        'properties' => ['template' => 'approval'],
        'occurred_at' => $occurredAt,
    ]);

    $milestone = Milestone::query()->create([
        'subject_type' => $subject->getMorphClass(),
        'subject_id' => (string) $subject->getKey(),
        'name' => 'workflow_created',
        'actor_type' => $actor->getMorphClass(),
        'actor_id' => (string) $actor->getKey(),
        'occurred_at' => $occurredAt,
    ]);

    expect($event->fresh()?->properties)->toBe(['template' => 'approval'])
        ->and($event->fresh()?->occurred_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($event->fresh()?->occurred_at->isUtc())->toBeTrue()
        ->and($event->subject->is($subject))->toBeTrue()
        ->and($event->actor?->is($actor))->toBeTrue()
        ->and($milestone->subject->is($subject))->toBeTrue()
        ->and($milestone->actor?->is($actor))->toBeTrue();
});

it('stores and hydrates occurred at in UTC when the application is not UTC', function (): void {
    runCustomerHealthStorageMigrations();
    createTrackableFixtures();

    $originalTimezone = date_default_timezone_get();
    config()->set('app.timezone', 'Australia/Brisbane');
    date_default_timezone_set('Australia/Brisbane');

    try {
        $subject = TestSubject::query()->create(['name' => 'Acme']);
        $event = ProductEventRecord::query()->create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => (string) $subject->getKey(),
            'name' => 'workflow_created',
            'feature' => 'workflows',
            'properties' => [],
            'occurred_at' => CarbonImmutable::parse('2026-08-08 10:00:00', 'Australia/Brisbane'),
        ]);

        expect(DB::table(TableNames::events())->value('occurred_at'))
            ->toStartWith('2026-08-08 00:00:00')
            ->and($event->fresh()?->occurred_at->format('Y-m-d H:i:s e'))
            ->toBe('2026-08-08 00:00:00 UTC');
    } finally {
        date_default_timezone_set($originalTimezone);
    }
});

it('uses renamed tables on a non-default connection end to end', function (): void {
    config()->set('database.connections.tenant', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('customer-health.connection', 'tenant');
    config()->set('customer-health.table_names.events', 'tenant_product_events');
    config()->set('customer-health.table_names.milestones', 'tenant_product_milestones');

    runCustomerHealthStorageMigrations();

    expect(Schema::connection('tenant')->hasTable('tenant_product_events'))->toBeTrue()
        ->and(Schema::connection('tenant')->hasTable('tenant_product_milestones'))->toBeTrue()
        ->and((new ProductEventRecord)->getConnectionName())->toBe('tenant')
        ->and((new ProductEventRecord)->getTable())->toBe('tenant_product_events')
        ->and((new Milestone)->getConnectionName())->toBe('tenant')
        ->and((new Milestone)->getTable())->toBe('tenant_product_milestones');
});
