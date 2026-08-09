<?php

declare(strict_types=1);

namespace Workbench\App\CustomerHealth;

use ByRcsc\LaravelCustomerHealth\Onboarding\Checklist;
use Workbench\App\CustomerHealth\Events\AccountCreated;
use Workbench\App\CustomerHealth\Events\TeammateInvited;
use Workbench\App\CustomerHealth\Events\WorkflowCreated;

final class Onboarding extends Checklist
{
    public function steps(): array
    {
        return [
            AccountCreated::class,
            WorkflowCreated::class,
            TeammateInvited::class,
        ];
    }
}
