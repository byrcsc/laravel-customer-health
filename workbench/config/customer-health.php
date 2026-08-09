<?php

declare(strict_types=1);

use ByRcsc\LaravelCustomerHealth\Tenancy\SpatieTenantResolver;
use Workbench\App\CustomerHealth\CustomerHealthScore;
use Workbench\App\CustomerHealth\Events\AccountCreated;
use Workbench\App\CustomerHealth\Events\TeammateInvited;
use Workbench\App\CustomerHealth\Events\WorkflowCreated;
use Workbench\App\CustomerHealth\Onboarding;

return array_replace_recursive(require __DIR__.'/../../config/customer-health.php', [
    'connection' => 'tenant',
    'summary_connection' => 'landlord',
    'tenant_resolver' => SpatieTenantResolver::class,
    'events' => [
        AccountCreated::class,
        WorkflowCreated::class,
        TeammateInvited::class,
    ],
    'checklists' => [Onboarding::class],
    'scores' => [CustomerHealthScore::class],
    'retention_days' => 30,
]);
