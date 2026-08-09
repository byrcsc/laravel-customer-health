<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use ByRcsc\LaravelCustomerHealth\Facades\CustomerHealth;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Workbench\App\CustomerHealth\Events\AccountCreated;
use Workbench\App\CustomerHealth\Events\TeammateInvited;
use Workbench\App\CustomerHealth\Events\WorkflowCreated;
use Workbench\App\Models\Team;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;

/**
 * Creates one healthy tenant and one tenant stalled in onboarding.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::query()->delete();

        foreach (['alpha', 'beta'] as $name) {
            $database = database_path("tenant-{$name}.sqlite");
            DB::purge('tenant');
            File::delete($database);
            File::put($database, '');

            $tenant = Tenant::query()->create([
                'name' => ucfirst($name),
                'domain' => "{$name}.test",
                'database' => $database,
            ]);

            $tenant->execute(function (): void {
                foreach (['events', 'milestones', 'scores'] as $storage) {
                    /** @var Migration $migration */
                    $migration = require __DIR__."/../../../database/migrations/create_customer_health_{$storage}_table.php.stub";
                    $migration->up();
                }

                Schema::connection('tenant')->create('teams', function (Blueprint $table): void {
                    $table->id();
                    $table->string('name');
                    $table->timestamps();
                });

                Schema::connection('tenant')->create('users', function (Blueprint $table): void {
                    $table->id();
                    $table->string('name');
                    $table->string('email')->unique();
                    $table->string('password');
                    $table->rememberToken();
                    $table->timestamps();
                });
            });

            $tenant->execute(fn () => $this->seedCustomerHealthStory($name));
        }
    }

    private function seedCustomerHealthStory(string $tenant): void
    {
        $team = Team::query()->create(['name' => ucfirst($tenant).' Team']);
        $user = User::query()->create([
            'name' => ucfirst($tenant).' Owner',
            'email' => "owner@{$tenant}.test",
            'password' => 'not-used',
        ]);

        if ($tenant === 'alpha') {
            CustomerHealth::track(new AccountCreated($team, actor: $user));
            CustomerHealth::track(new WorkflowCreated($team, actor: $user));
            CustomerHealth::track(new TeammateInvited($team, actor: $user));

            return;
        }

        CustomerHealth::track(new AccountCreated(
            $team,
            actor: $user,
            occurredAt: CarbonImmutable::now('UTC')->subDays(45),
        ));
    }
}
