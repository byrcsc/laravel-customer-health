<?php

declare(strict_types=1);

use ByRcsc\LaravelCustomerHealth\Contracts\Trackable;
use ByRcsc\LaravelCustomerHealth\Events\ProductEvent;
use ByRcsc\LaravelCustomerHealth\Facades\CustomerHealth;
use ByRcsc\LaravelCustomerHealth\Models\HealthScoreRecord;
use ByRcsc\LaravelCustomerHealth\Models\HealthSummary;
use ByRcsc\LaravelCustomerHealth\Models\ProductEventRecord;
use ByRcsc\LaravelCustomerHealth\Scoring\HealthScore;
use ByRcsc\LaravelCustomerHealth\Scoring\Signal;
use ByRcsc\LaravelCustomerHealth\Support\TableNames;
use ByRcsc\LaravelCustomerHealth\Tenancy\SpatieTenantResolver;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Models\Tenant;
use Spatie\Multitenancy\Tasks\SwitchTenantDatabaseTask;
use Spatie\Multitenancy\Tasks\TasksCollection;

beforeEach(function (): void {
    dropCustomerHealthStorage('testing');
    configureSpatieMultitenancy();
    Schema::connection('testing')->dropIfExists('tenants');
    Schema::connection('testing')->create('tenants', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('domain')->unique();
        $table->string('database')->unique();
        $table->timestamps();
    });

    config()->set('customer-health.connection', 'tenant');
    config()->set('customer-health.summary_connection', 'testing');
    config()->set('customer-health.tenant_resolver', SpatieTenantResolver::class);
    config()->set('customer-health.events', [TenantActivated::class]);
    config()->set('customer-health.checklists', []);
    config()->set('customer-health.scores', [TenantHealthScore::class]);
    runCustomerHealthSummaryMigration();

    $this->tenantDatabases = createTenantDatabases();
    $this->tenants = collect($this->tenantDatabases)
        ->map(fn (string $database, string $name): CompatibilityTenant => CompatibilityTenant::query()->create([
            'name' => ucfirst($name),
            'domain' => "{$name}.test",
            'database' => $database,
        ]));

    $this->tenants->each(function (CompatibilityTenant $tenant): void {
        $tenant->execute(function (): void {
            runTenantCustomerHealthMigrations();
            Schema::connection('tenant')->create('tenant_subjects', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
            });
        });
    });
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    DB::purge('tenant');
    Schema::connection('testing')->dropIfExists(TableNames::summaries());
    Schema::connection('testing')->dropIfExists('jobs');
    Schema::connection('testing')->dropIfExists('tenants');
    dropTenantDatabases($this->tenantDatabases ?? []);
    config()->set('database.connections.tenant');
    config()->set('customer-health.connection');
    config()->set('customer-health.summary_connection');
    config()->set('customer-health.events', []);
    config()->set('customer-health.scores', []);
});

it('tracks and recomputes isolated tenant data into landlord summaries', function (): void {
    seedTenantEvent($this->tenants['alpha'], 'Healthy');
    seedTenantEvent($this->tenants['beta'], 'At risk');

    expect(Artisan::call('tenants:artisan', [
        'artisanCommand' => 'customer-health:recompute',
    ]))->toBe(Command::SUCCESS);

    assertTenantRows($this->tenants['alpha'], expectedEvents: 1, expectedScores: 1);
    assertTenantRows($this->tenants['beta'], expectedEvents: 1, expectedScores: 1);

    expect(HealthSummary::on('testing')->count())->toBe(2)
        ->and(HealthSummary::on('testing')->pluck('tenant_id')->sort()->values()->all())
        ->toBe($this->tenants->keys()->map(fn (string $name): string => (string) $this->tenants[$name]->getKey())->sort()->values()->all())
        ->and(CustomerHealth::inState('at_risk')->get())->toHaveCount(1)
        ->and(CustomerHealth::inState('at_risk')->value('tenant_id'))->toBe((string) $this->tenants['beta']->getKey());
});

it('restores the dispatching tenant for queued writes', function (): void {
    Schema::connection('testing')->create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.connection', 'testing');
    config()->set('queue.connections.database.table', 'jobs');
    config()->set('customer-health.queue', true);
    config()->set('customer-health.queue_connection', 'database');

    seedTenantEvent($this->tenants['alpha'], 'Alpha');
    seedTenantEvent($this->tenants['beta'], 'Beta');

    expect(DB::connection('testing')->table('jobs')->count())->toBe(2);
    Tenant::forgetCurrent();
    expect(Artisan::call('queue:work', [
        'connection' => 'database',
        '--stop-when-empty' => true,
    ]))->toBe(Command::SUCCESS)
        ->and(Tenant::current())->toBeNull()
        ->and(DB::connection('testing')->table('jobs')->count())->toBe(0);

    assertTenantRows($this->tenants['alpha'], expectedEvents: 1, expectedScores: 0);
    assertTenantRows($this->tenants['beta'], expectedEvents: 1, expectedScores: 0);
});

it('resolves the current Spatie tenant through the optional adapter', function (): void {
    expect((new SpatieTenantResolver)())->toBeNull();

    $this->tenants['alpha']->execute(function (): void {
        expect((new SpatieTenantResolver)())->toBe($this->tenants['alpha']->getKey());
    });
});

it('keeps the package core independent from Spatie outside its guarded adapter', function (): void {
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../../src'),
    );

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    foreach ($files as $file) {
        if (str_ends_with($file, '/Tenancy/SpatieTenantResolver.php')) {
            continue;
        }

        expect(file_get_contents($file))->not->toContain('Spatie\\Multitenancy');
    }
});

final class CompatibilityTenant extends Tenant
{
    protected $table = 'tenants';

    protected $guarded = [];
}

final class TenantSubject extends Model implements Trackable
{
    public $timestamps = false;

    protected $connection = 'tenant';

    protected $table = 'tenant_subjects';

    protected $guarded = [];
}

final class TenantActivated extends ProductEvent
{
    public static string $feature = 'activation';

    public static bool $milestone = true;
}

final class TenantHealthScore extends HealthScore
{
    public function signals(): array
    {
        return [new TenantNameSignal];
    }

    public function states(): array
    {
        return ['at_risk' => 0, 'healthy' => 75];
    }
}

final readonly class TenantNameSignal implements Signal
{
    public function evaluate(Trackable $subject): int
    {
        return $subject instanceof TenantSubject && $subject->name === 'Healthy' ? 100 : 20;
    }

    public function weight(): float
    {
        return 1;
    }
}

function configureSpatieMultitenancy(): void
{
    /** @var array<string, mixed> $tenantConnection */
    $tenantConnection = config('database.connections.testing');
    $tenantConnection['database'] = null;
    config()->set('database.connections.tenant', $tenantConnection);
    config()->set('multitenancy.tenant_model', CompatibilityTenant::class);
    config()->set('multitenancy.switch_tenant_tasks', [SwitchTenantDatabaseTask::class]);
    config()->set('multitenancy.tenant_database_connection_name', 'tenant');
    config()->set('multitenancy.landlord_database_connection_name', 'testing');
    config()->set('multitenancy.queues_are_tenant_aware_by_default', true);

    app()->forgetInstance(IsTenant::class);
    app()->bind(IsTenant::class, CompatibilityTenant::class);
    app()->forgetInstance(TasksCollection::class);
}

/** @return array{alpha: string, beta: string} */
function createTenantDatabases(): array
{
    /** @var array<string, mixed> $connection */
    $connection = config('database.connections.testing');
    $driver = $connection['driver'] ?? 'sqlite';

    if ($driver === 'sqlite') {
        return [
            'alpha' => tempnam(sys_get_temp_dir(), 'customer-health-alpha-'),
            'beta' => tempnam(sys_get_temp_dir(), 'customer-health-beta-'),
        ];
    }

    $databases = [
        'alpha' => 'customer_health_tenant_alpha_test',
        'beta' => 'customer_health_tenant_beta_test',
    ];

    foreach ($databases as $database) {
        if ($driver === 'mysql') {
            DB::connection('testing')->unprepared("DROP DATABASE IF EXISTS `{$database}`");
            DB::connection('testing')->unprepared("CREATE DATABASE `{$database}`");
        } else {
            DB::connection('testing')->statement(
                'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()',
                [$database],
            );
            DB::connection('testing')->unprepared("DROP DATABASE IF EXISTS \"{$database}\"");
            DB::connection('testing')->unprepared("CREATE DATABASE \"{$database}\"");
        }
    }

    return $databases;
}

/** @param array<string, string> $databases */
function dropTenantDatabases(array $databases): void
{
    /** @var array<string, mixed> $connection */
    $connection = config('database.connections.testing');
    $driver = $connection['driver'] ?? 'sqlite';

    foreach ($databases as $database) {
        if ($driver === 'sqlite') {
            if (is_file($database)) {
                unlink($database);
            }

            continue;
        }

        if ($driver === 'mysql') {
            DB::connection('testing')->unprepared("DROP DATABASE IF EXISTS `{$database}`");
        } else {
            DB::connection('testing')->statement(
                'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()',
                [$database],
            );
            DB::connection('testing')->unprepared("DROP DATABASE IF EXISTS \"{$database}\"");
        }
    }
}

function runTenantCustomerHealthMigrations(): void
{
    foreach (['create_customer_health_events_table', 'create_customer_health_milestones_table', 'create_customer_health_scores_table'] as $migration) {
        /** @var Migration $instance */
        $instance = require __DIR__."/../../database/migrations/{$migration}.php.stub";
        $instance->up();
    }
}

function seedTenantEvent(CompatibilityTenant $tenant, string $name): void
{
    $tenant->execute(function () use ($name): void {
        $subject = TenantSubject::query()->create(['name' => $name]);
        CustomerHealth::track(new TenantActivated($subject));
    });
}

function assertTenantRows(CompatibilityTenant $tenant, int $expectedEvents, int $expectedScores): void
{
    $tenant->execute(function () use ($expectedEvents, $expectedScores): void {
        expect(ProductEventRecord::on('tenant')->count())->toBe($expectedEvents)
            ->and(HealthScoreRecord::on('tenant')->count())->toBe($expectedScores);
    });
}
