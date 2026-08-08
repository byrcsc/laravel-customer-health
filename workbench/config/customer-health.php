<?php

declare(strict_types=1);

use ByRcsc\LaravelCustomerHealth\Tenancy\SpatieTenantResolver;

return array_replace_recursive(require __DIR__.'/../../config/customer-health.php', [
    'connection' => 'tenant',
    'summary_connection' => 'landlord',
    'tenant_resolver' => SpatieTenantResolver::class,
]);
