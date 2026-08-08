<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Tests\Stubs;

use ByRcsc\LaravelCustomerHealth\Models\BaseModel;

/**
 * The smallest possible concrete package model: what every real model from
 * issue A2 onwards looks like to the storage layer. It exists so the base
 * model's config resolution is provable before any real table does.
 */
final class HealthRecord extends BaseModel
{
    protected static function tableKey(): string
    {
        return 'events';
    }
}
