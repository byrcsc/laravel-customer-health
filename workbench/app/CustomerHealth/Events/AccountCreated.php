<?php

declare(strict_types=1);

namespace Workbench\App\CustomerHealth\Events;

use ByRcsc\LaravelCustomerHealth\Events\ProductEvent;

final class AccountCreated extends ProductEvent
{
    public static string $feature = 'accounts';

    public static bool $milestone = true;
}
