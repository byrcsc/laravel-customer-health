<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Support;

use ByRcsc\LaravelCustomerHealth\Exceptions\ScoreComputationLockException;
use Illuminate\Database\Connection;

final class ScoreComputationLock
{
    public function acquire(Connection $connection, string $key): void
    {
        $key = $connection->getDatabaseName().'|'.$key;

        if ($connection->getDriverName() === 'pgsql') {
            $connection->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);

            return;
        }

        if ($connection->getDriverName() !== 'mysql') {
            return;
        }

        $lockName = 'customer-health:'.substr(hash('sha256', $key), 0, 48);
        $result = $connection->selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);

        if (! is_object($result)) {
            throw ScoreComputationLockException::timedOut();
        }

        $acquired = ((array) $result)['acquired'] ?? null;
        if ($acquired !== 1 && $acquired !== '1') {
            throw ScoreComputationLockException::timedOut();
        }

        $release = static function () use ($connection, $lockName): void {
            $connection->selectOne('SELECT RELEASE_LOCK(?)', [$lockName]);
        };
        $connection->afterCommit($release);
        $connection->afterRollBack($release);
    }
}
