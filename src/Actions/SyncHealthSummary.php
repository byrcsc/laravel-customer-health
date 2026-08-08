<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Actions;

use ByRcsc\LaravelCustomerHealth\Data\MorphIdentity;
use ByRcsc\LaravelCustomerHealth\Models\HealthScoreRecord;
use ByRcsc\LaravelCustomerHealth\Models\HealthSummary;
use ByRcsc\LaravelCustomerHealth\Tenancy\ResolveTenantId;

final readonly class SyncHealthSummary
{
    public function __construct(private ResolveTenantId $tenantId) {}

    public function handle(MorphIdentity $subject, HealthScoreRecord $record): void
    {
        $tenantId = $this->tenantId->resolve();

        HealthSummary::query()->upsert([[
            'summary_key' => hash('sha256', json_encode([
                $tenantId,
                $subject->type,
                $subject->id,
                $record->score,
            ], JSON_THROW_ON_ERROR)),
            'tenant_id' => $tenantId,
            'subject_type' => $subject->type,
            'subject_id' => $subject->id,
            'score' => $record->score,
            'value' => $record->value,
            'state' => $record->state,
            'computed_at' => $record->computed_at,
        ]], ['summary_key'], [
            'value', 'state', 'computed_at', 'updated_at',
        ]);
    }
}
