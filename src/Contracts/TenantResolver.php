<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Contracts;

interface TenantResolver
{
    public function __invoke(): int|string|null;
}
