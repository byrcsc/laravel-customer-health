<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Tests\Stubs;

use ByRcsc\LaravelCustomerHealth\Events\ProductEvent;

final class WorkflowCreated extends ProductEvent
{
    public static string $feature = 'workflows';

    public static bool $milestone = true;
}
