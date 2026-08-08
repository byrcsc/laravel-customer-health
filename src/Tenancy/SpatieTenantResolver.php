<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Tenancy;

use ByRcsc\LaravelCustomerHealth\Contracts\TenantResolver;
use Spatie\Multitenancy\Models\Tenant;

final readonly class SpatieTenantResolver implements TenantResolver
{
    public function __invoke(): int|string|null
    {
        if (! class_exists(Tenant::class)) {
            return null;
        }

        $key = Tenant::current()?->getKey();

        return is_int($key) || is_string($key) ? $key : null;
    }
}
