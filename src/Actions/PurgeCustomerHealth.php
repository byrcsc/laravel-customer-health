<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Actions;

use ByRcsc\LaravelCustomerHealth\Contracts\Trackable;
use ByRcsc\LaravelCustomerHealth\Data\MorphIdentity;
use ByRcsc\LaravelCustomerHealth\Models\HealthScoreRecord;
use ByRcsc\LaravelCustomerHealth\Models\HealthSummary;
use ByRcsc\LaravelCustomerHealth\Models\Milestone;
use ByRcsc\LaravelCustomerHealth\Models\ProductEventRecord;
use ByRcsc\LaravelCustomerHealth\Tenancy\ResolveTenantId;
use Illuminate\Database\ConnectionInterface;

final readonly class PurgeCustomerHealth
{
    public function __construct(private ResolveTenantId $tenantId) {}

    public function handle(Trackable $subject, int|string|null $tenantId = null): void
    {
        $identity = MorphIdentity::from($subject, 'subject');
        $tenantId ??= $this->tenantId->resolve();
        $history = (new ProductEventRecord)->getConnection();
        $summary = (new HealthSummary)->getConnection();

        if ($history->getName() === $summary->getName()) {
            $history->transaction(function () use ($history, $identity, $tenantId): void {
                $this->deleteHistory($history, $identity);
                $this->deleteSummary($history, $identity, $tenantId);
            });

            return;
        }

        $history->transaction(fn () => $this->deleteHistory($history, $identity));
        $summary->transaction(fn () => $this->deleteSummary($summary, $identity, $tenantId));
    }

    private function deleteHistory(ConnectionInterface $connection, MorphIdentity $subject): void
    {
        foreach ([ProductEventRecord::class, Milestone::class, HealthScoreRecord::class] as $model) {
            $instance = new $model;
            $connection->table($instance->getTable())
                ->where('subject_type', $subject->type)
                ->where('subject_id', $subject->id)
                ->delete();
        }
    }

    private function deleteSummary(
        ConnectionInterface $connection,
        MorphIdentity $subject,
        int|string|null $tenantId,
    ): void {
        $summary = new HealthSummary;
        $query = $connection->table($summary->getTable())
            ->where('subject_type', $subject->type)
            ->where('subject_id', $subject->id);

        $tenantId === null
            ? $query->whereNull('tenant_id')
            : $query->where('tenant_id', (string) $tenantId);

        $query->delete();
    }
}
