<?php

declare(strict_types=1);

use Spatie\Multitenancy\Tasks\SwitchTenantDatabaseTask;
use Workbench\App\Models\Tenant;

return array_replace_recursive(require __DIR__.'/../../vendor/spatie/laravel-multitenancy/config/multitenancy.php', [
    'tenant_model' => Tenant::class,
    'switch_tenant_tasks' => [SwitchTenantDatabaseTask::class],
    'tenant_database_connection_name' => 'tenant',
    'landlord_database_connection_name' => 'landlord',
    'queues_are_tenant_aware_by_default' => true,
]);
