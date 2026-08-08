<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Commands;

use ByRcsc\LaravelCustomerHealth\Actions\PurgeCustomerHealth;
use ByRcsc\LaravelCustomerHealth\Contracts\Trackable;
use ByRcsc\LaravelCustomerHealth\Data\MorphIdentity;
use Illuminate\Console\Command;

final class PurgeCustomerHealthCommand extends Command
{
    protected $signature = 'customer-health:purge
        {subject_type : Model class or morph alias}
        {subject_id : Model key}
        {--tenant= : Override the tenant id matched in landlord summaries}';

    protected $description = 'Erase all customer health data for one subject';

    public function handle(PurgeCustomerHealth $purge): int
    {
        $type = $this->argument('subject_type');
        $id = $this->argument('subject_id');
        $tenant = $this->option('tenant');

        if ($type === '' || $id === '') {
            $this->error('The subject identity is invalid.');

            return self::FAILURE;
        }

        $subject = (new MorphIdentity($type, $id))->resolve();
        if (! $subject instanceof Trackable) {
            $this->error("The subject [{$type}:{$id}] could not be resolved as a trackable model.");

            return self::FAILURE;
        }

        $tenantId = is_string($tenant) && $tenant !== '' ? $tenant : null;
        $purge->handle($subject, $tenantId);
        $this->info("Purged customer health data for [{$type}:{$id}].");

        return self::SUCCESS;
    }
}
