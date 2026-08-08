<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Models;

use ByRcsc\LaravelCustomerHealth\Casts\UtcImmutableDateTime;
use ByRcsc\LaravelCustomerHealth\Concerns\HasSubjectAndActorRelations;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A readable permanent first occurrence. Applications should write product
 * events through CustomerHealth::track() so uniqueness and events stay in
 * sync.
 *
 * @property CarbonImmutable $occurred_at
 * @property-read Model $subject
 * @property-read Model|null $actor
 */
final class Milestone extends BaseModel
{
    use HasSubjectAndActorRelations;

    public const UPDATED_AT = null;

    protected static function tableKey(): string
    {
        return 'milestones';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => UtcImmutableDateTime::class,
        ];
    }
}
