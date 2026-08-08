<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Tenancy;

use ByRcsc\LaravelCustomerHealth\Contracts\TenantResolver;

final class NullTenantResolver implements TenantResolver
{
    public function __invoke(): null
    {
        return null;
    }
}
