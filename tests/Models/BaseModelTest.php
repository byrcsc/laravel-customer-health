<?php

declare(strict_types=1);

use ByRcsc\LaravelCustomerHealth\Support\TableNames;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\HealthRecord;

it('resolves its table from the config defaults', function (): void {
    expect((new HealthRecord)->getTable())->toBe('customer_health_events');
});

it('respects a renamed table', function (): void {
    config()->set('customer-health.table_names.events', 'product_events');

    expect((new HealthRecord)->getTable())->toBe('product_events');
});

it('falls back to the shipped default when a published config misses a key', function (): void {
    config()->set('customer-health.table_names', []);

    expect((new HealthRecord)->getTable())->toBe('customer_health_events');
});

it('uses the default connection when none is configured', function (): void {
    $record = new HealthRecord;

    expect($record->getConnectionName())->toBeNull()
        ->and($record->getConnection()->getName())->toBe('testing');
});

it('respects a configured connection', function (): void {
    config()->set('database.connections.tenant', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('customer-health.connection', 'tenant');

    expect((new HealthRecord)->getConnectionName())->toBe('tenant')
        ->and((new HealthRecord)->getConnection()->getName())->toBe('tenant');
});

it('lets an explicit per-instance connection win over the configured one', function (): void {
    config()->set('customer-health.connection', 'tenant');

    $record = new HealthRecord;
    $record->setConnection('testing');

    expect($record->getConnectionName())->toBe('testing');
});

it('exposes every configurable table through TableNames', function (): void {
    expect(TableNames::keys())->toBe(['events', 'milestones', 'scores', 'summaries'])
        ->and(TableNames::events())->toBe('customer_health_events')
        ->and(TableNames::milestones())->toBe('customer_health_milestones')
        ->and(TableNames::scores())->toBe('customer_health_scores')
        ->and(TableNames::summaries())->toBe('customer_health_summaries')
        ->and(TableNames::defaults())->toBe([
            'events' => 'customer_health_events',
            'milestones' => 'customer_health_milestones',
            'scores' => 'customer_health_scores',
            'summaries' => 'customer_health_summaries',
        ]);
});
