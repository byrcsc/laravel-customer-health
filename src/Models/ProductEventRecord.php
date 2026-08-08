<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Models;

use ByRcsc\LaravelCustomerHealth\Casts\UtcImmutableDateTime;
use ByRcsc\LaravelCustomerHealth\Concerns\HasSubjectAndActorRelations;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A readable raw product-event record. Applications should write events
 * through CustomerHealth::track() so milestone invariants remain intact.
 *
 * @property array<string, mixed> $properties
 * @property CarbonImmutable $occurred_at
 * @property-read Model $subject
 * @property-read Model|null $actor
 */
final class ProductEventRecord extends BaseModel
{
    use HasSubjectAndActorRelations;

    public const UPDATED_AT = null;

    protected static function tableKey(): string
    {
        return 'events';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'occurred_at' => UtcImmutableDateTime::class,
        ];
    }
}
