<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Models;

use ByRcsc\LaravelCustomerHealth\Casts\UtcImmutableDateTime;
use ByRcsc\LaravelCustomerHealth\ValueObjects\ScoreResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $subject_type
 * @property string $subject_id
 * @property string $score
 * @property int $value
 * @property string $state
 * @property list<array{signal: class-string, raw: int, weight: float, contribution: float}> $breakdown
 * @property CarbonImmutable $computed_at
 */
final class HealthScoreRecord extends BaseModel
{
    public $timestamps = false;

    protected static function tableKey(): string
    {
        return 'scores';
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function toScoreResult(): ScoreResult
    {
        return ScoreResult::fromRecord($this);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'breakdown' => 'array',
            'computed_at' => UtcImmutableDateTime::class,
        ];
    }
}
