<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Models;

use ByRcsc\LaravelCustomerHealth\Support\TableNames;
use Illuminate\Database\Eloquent\Model;

/**
 * Every package model resolves its table through `TableNames` and its
 * configured connection, so renaming a table or moving package storage is a
 * config change, not a code change. Concrete models name their `TableNames`
 * key; the summary model only overrides which configured connection it uses.
 */
abstract class BaseModel extends Model
{
    /** @var list<string> */
    protected $guarded = [];

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

        $configured = $this->configuredConnection();

        return is_string($configured) && $configured !== '' ? $configured : null;
    }

    protected function configuredConnection(): mixed
    {
        return config('customer-health.connection');
    }
}
