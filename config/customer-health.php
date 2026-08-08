<?php

declare(strict_types=1);

use ByRcsc\LaravelCustomerHealth\Support\TableNames;

return [

    /*
    |--------------------------------------------------------------------------
    | Table names
    |--------------------------------------------------------------------------
    |
    | Rename these to fit your schema conventions; every model and migration
    | resolves through the same values, so nothing else has to change. Set
    | them before migrating. Renaming a table that already holds history
    | requires your own migration.
    |
    */

    'table_names' => TableNames::defaults(),

    /*
    |--------------------------------------------------------------------------
    | Database connection
    |--------------------------------------------------------------------------
    |
    | The connection events, milestones, and scores are written to. Null means
    | the application's default connection, which is exactly what
    | database-per-tenant packages switch: leave this null and per-tenant
    | writes land in the current tenant database.
    |
    */

    'connection' => null,

    /*
    |--------------------------------------------------------------------------
    | Summary connection
    |--------------------------------------------------------------------------
    |
    | The connection the compact summaries table lives on. Null means the same
    | as `connection` above. In a database-per-tenant app, point this at the
    | landlord connection so "show me every at-risk customer" stays one query
    | across all tenant databases.
    |
    */

    'summary_connection' => null,

    /*
    |--------------------------------------------------------------------------
    | Product events
    |--------------------------------------------------------------------------
    |
    | Every product event must be registered. This lets the package resolve
    | event names back to declarations for milestone and feature queries,
    | and makes missing configuration fail before data is silently dropped.
    |
    */

    'events' => [],

];
