<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Tenancy;

use ByRcsc\LaravelCustomerHealth\Exceptions\InvalidTenantResolverException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;

final readonly class ResolveTenantId
{
    public function __construct(private Repository $config, private Container $container) {}

    public function resolve(): ?string
    {
        $class = $this->config->get('customer-health.tenant_resolver', NullTenantResolver::class);

        if (! is_string($class) || ! class_exists($class)) {
            throw InvalidTenantResolverException::invalid();
        }

        $resolver = $this->container->make($class);
        if (! is_callable($resolver)) {
            throw InvalidTenantResolverException::invalid();
        }

        $tenant = $resolver();
        if ($tenant === null) {
            return null;
        }

        if ((! is_string($tenant) && ! is_int($tenant)) || (string) $tenant === '') {
            throw InvalidTenantResolverException::invalid();
        }

        return (string) $tenant;
    }
}
