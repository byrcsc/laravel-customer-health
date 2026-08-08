<?php

declare(strict_types=1);

return [
    'default' => 'landlord',

    'connections' => [
        'landlord' => [
            'driver' => 'sqlite',
            'database' => database_path('database.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],

        'tenant' => [
            'driver' => 'sqlite',
            'database' => null,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ],

    'migrations' => 'migrations',
];
