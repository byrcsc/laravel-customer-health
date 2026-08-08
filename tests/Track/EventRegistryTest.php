<?php

declare(strict_types=1);

use ByRcsc\LaravelCustomerHealth\Events\ProductEvent;
use ByRcsc\LaravelCustomerHealth\Exceptions\UnregisteredEventException;
use ByRcsc\LaravelCustomerHealth\Registry\EventRegistry;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\AccountCreated;
use ByRcsc\LaravelCustomerHealth\Tests\Stubs\WorkflowCreated;

it('indexes registered events by name feature and milestone status', function (): void {
    config()->set('customer-health.events', [
        WorkflowCreated::class,
        AccountCreated::class,
    ]);

    $registry = app(EventRegistry::class);

    expect($registry->classFor('workflow_created'))->toBe(WorkflowCreated::class)
        ->and($registry->nameFor(WorkflowCreated::class))->toBe('workflow_created')
        ->and($registry->eventsForFeature('workflows'))->toBe([WorkflowCreated::class])
        ->and($registry->milestoneEvents())->toBe([WorkflowCreated::class])
        ->and($registry->features())->toBe([
            'workflows' => [WorkflowCreated::class],
            'accounts' => [AccountCreated::class],
        ]);
});

it('derives event names and honours explicit names', function (): void {
    expect(WorkflowCreated::name())->toBe('workflow_created')
        ->and(AccountCreated::name())->toBe('account_opened');
});

it('rejects unregistered event classes in every environment', function (): void {
    config()->set('app.env', 'production');
    config()->set('customer-health.events', [WorkflowCreated::class]);

    app(EventRegistry::class)->nameFor(UnregisteredProductEvent::class);
})->throws(UnregisteredEventException::class);

final class UnregisteredProductEvent extends ProductEvent
{
    public static string $feature = 'unknown';
}
