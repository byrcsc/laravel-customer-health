<?php

declare(strict_types=1);

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

    'table_names' => [
        'events' => 'customer_health_events',
        'milestones' => 'customer_health_milestones',
        'scores' => 'customer_health_scores',
        'summaries' => 'customer_health_summaries',
    ],

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

];
