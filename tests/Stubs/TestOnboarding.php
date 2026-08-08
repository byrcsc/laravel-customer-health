<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Tests\Stubs;

use ByRcsc\LaravelCustomerHealth\Onboarding\Checklist;

final class TestOnboarding extends Checklist
{
    public function steps(): array
    {
        return [WorkflowCreated::class, TeammateInvited::class];
    }
}
