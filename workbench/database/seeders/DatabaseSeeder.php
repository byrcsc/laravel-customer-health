<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Workbench\App\Models\Tenant;

/**
 * Creates the two empty tenant databases used by the compatibility workbench.
 * Product events and richer demo data land with issue E2.
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
            });
        }
    }
}
