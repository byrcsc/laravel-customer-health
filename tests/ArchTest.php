<?php

declare(strict_types=1);

arch('no debugging leftovers ship')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('everything declares strict types')
    ->expect('ByRcsc\LaravelCustomerHealth')
    ->toUseStrictTypes();

arch('models are final and extend the package base model')
    ->expect('ByRcsc\LaravelCustomerHealth\Models')
    ->classes()
    ->toExtend('ByRcsc\LaravelCustomerHealth\Models\BaseModel')
    ->toBeFinal()
    ->ignoring('ByRcsc\LaravelCustomerHealth\Models\BaseModel');

// Models describe the schema and its truth table. Transactions and event
// dispatching belong to the write-side methods, and this keeps it that way.
arch('models stay out of the transaction business')
    ->expect('ByRcsc\LaravelCustomerHealth\Models')
    ->not->toUse([
        'Illuminate\Support\Facades\DB',
        'Illuminate\Support\Facades\Event',
    ]);

// Table names and connections are config, resolved through TableNames and the
// base model — a literal table string, a declared $table/$connection property,
// a re-overridden resolver, or a literal connection switch would silently stop
// respecting that config. TableNames itself is the one file allowed to know
// the default names.
it('never hard-codes table names or connections outside TableNames', function (): void {
    $files = [];

    foreach (['src', 'database/migrations'] as $root) {
        $path = __DIR__.'/../'.$root;

        if (! is_dir($path)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && str_contains($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }
    }

    expect($files)->not->toBeEmpty();

    $forbidden = [
        "'customer_health_",
        '"customer_health_',
        '$table =',
        '$connection =',
        "setConnection('",
        'setConnection("',
        "::on('",
        '::on("',
    ];

    foreach ($files as $file) {
        if (str_ends_with($file, 'Support/TableNames.php')) {
            continue;
        }

        $code = (string) file_get_contents($file);

        // One needle per expectation: `not->toContain(a, b)` negates the
        // conjunction, so it would pass as soon as any one needle is absent.
        foreach ($forbidden as $needle) {
            expect($code)->not->toContain($needle);
        }

        // Only the base model may define how table and connection resolve;
        // a subclass re-overriding either escapes the config.
        if (str_contains($file, '/Models/') && ! str_ends_with($file, 'Models/BaseModel.php')) {
            expect($code)->not->toContain('function getTable(');
            expect($code)->not->toContain('function getConnectionName(');
        }
    }
});
