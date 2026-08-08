<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Tests\Stubs;

use ByRcsc\LaravelCustomerHealth\Events\ProductEvent;

final class TeammateInvited extends ProductEvent
{
    public static string $feature = 'team';

    public static bool $milestone = true;
}
