<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Tests\Stubs;

use ByRcsc\LaravelCustomerHealth\Events\ProductEvent;

final class AccountCreated extends ProductEvent
{
    public static string $feature = 'accounts';

    public static string $name = 'account_opened';
}
