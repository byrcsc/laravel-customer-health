<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\ValueObjects;

use ByRcsc\LaravelCustomerHealth\Models\HealthScoreRecord;
use Carbon\CarbonImmutable;

final readonly class ScoreResult
{
    /**
     * @param  list<array{signal: class-string, raw: int, weight: float, contribution: float}>  $breakdown
     */
    public function __construct(
        public string $score,
        public int $value,
        public string $state,
        public array $breakdown,
        public CarbonImmutable $computedAt,
    ) {}

    public static function fromRecord(HealthScoreRecord $record): self
    {
        $breakdown = array_map(static fn (array $entry): array => [
            'signal' => $entry['signal'],
            'raw' => $entry['raw'],
            'weight' => (float) $entry['weight'],
            'contribution' => (float) $entry['contribution'],
        ], $record->breakdown);

        return new self(
            score: $record->score,
            value: $record->value,
            state: $record->state,
            breakdown: $breakdown,
            computedAt: $record->computed_at,
        );
    }
}
