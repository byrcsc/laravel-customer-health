<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Models;

use ByRcsc\LaravelCustomerHealth\Casts\UtcImmutableDateTime;
use ByRcsc\LaravelCustomerHealth\Data\MorphIdentity;
use Carbon\CarbonImmutable;

/**
 * @property string|null $tenant_id
 * @property string $summary_key
 * @property string $subject_type
 * @property string $subject_id
 * @property string $score
 * @property int $value
 * @property string $state
 * @property CarbonImmutable $computed_at
 */
final class HealthSummary extends BaseModel
{
    protected static function tableKey(): string
    {
        return 'summaries';
    }

    public function subjectIdentity(): MorphIdentity
    {
        return new MorphIdentity($this->subject_type, $this->subject_id);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'computed_at' => UtcImmutableDateTime::class,
        ];
    }

    protected function configuredConnection(): mixed
    {
        $summary = config('customer-health.summary_connection');

        return is_string($summary) && $summary !== '' ? $summary : parent::configuredConnection();
    }
}
