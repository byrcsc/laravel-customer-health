<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Queries;

use ByRcsc\LaravelCustomerHealth\Data\MorphIdentity;
use ByRcsc\LaravelCustomerHealth\Models\Milestone;
use ByRcsc\LaravelCustomerHealth\Models\ProductEventRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

final readonly class InactiveSubjectsQuery
{
    public function __construct(private int $days) {}

    /** @return Collection<int, MorphIdentity> */
    public function get(): Collection
    {
        $eventSubjects = ProductEventRecord::query()->select(['subject_type', 'subject_id']);
        $knownSubjects = Milestone::query()
            ->select(['subject_type', 'subject_id'])
            ->union($eventSubjects);
        $activity = ProductEventRecord::query()
            ->selectRaw('subject_type, subject_id, MAX(occurred_at) AS last_seen')
            ->groupBy(['subject_type', 'subject_id']);

        $rows = (new ProductEventRecord)->getConnection()
            ->query()
            ->fromSub($knownSubjects->toBase(), 'known_subjects')
            ->leftJoinSub($activity->toBase(), 'activity', function (JoinClause $join): void {
                $join->on('known_subjects.subject_type', '=', 'activity.subject_type')
                    ->on('known_subjects.subject_id', '=', 'activity.subject_id');
            })
            ->where(function (Builder $query): void {
                $query->whereNull('activity.last_seen')
                    ->orWhere('activity.last_seen', '<', CarbonImmutable::now('UTC')->subDays($this->days));
            })
            ->select(['known_subjects.subject_type', 'known_subjects.subject_id'])
            ->get();

        return $rows->map(fn (object $row): MorphIdentity => new MorphIdentity(
            type: $this->stringProperty($row, 'subject_type'),
            id: $this->stringProperty($row, 'subject_id'),
        ));
    }

    private function stringProperty(object $row, string $property): string
    {
        $value = $row->{$property} ?? null;

        if (! is_string($value) && ! is_int($value)) {
            return '';
        }

        return (string) $value;
    }
}
