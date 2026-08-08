<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Models;

use ByRcsc\LaravelCustomerHealth\Support\TableNames;
use Illuminate\Database\Eloquent\Model;

/**
 * Every package model resolves its table through `TableNames` and its
 * connection through `config('customer-health.connection')`, so renaming a
 * table or pointing the package at another database is a config change, not
 * a code change. Concrete models name their `TableNames` key and nothing
 * else about storage.
 */
abstract class BaseModel extends Model
{
    /**
     * The `TableNames` key this model's table is configured under.
     */
    abstract protected static function tableKey(): string;

    public function getTable(): string
    {
        return TableNames::for(static::tableKey());
    }

    public function getConnectionName(): ?string
    {
        // An explicitly set per-instance connection (`Model::on()`) wins;
        // otherwise the configured connection, where null means the
        // application's default — the one tenancy packages switch.
        $instance = parent::getConnectionName();

        if ($instance !== null) {
            return $instance;
        }

        /** @var mixed $configured */
        $configured = config('customer-health.connection');

        return is_string($configured) && $configured !== '' ? $configured : null;
    }
}
