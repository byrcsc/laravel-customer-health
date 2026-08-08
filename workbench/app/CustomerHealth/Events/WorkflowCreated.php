<?php

declare(strict_types=1);

namespace Workbench\App\CustomerHealth\Events;

use ByRcsc\LaravelCustomerHealth\Events\ProductEvent;

final class WorkflowCreated extends ProductEvent
{
    public static string $feature = 'workflows';

    public static bool $milestone = true;
}
