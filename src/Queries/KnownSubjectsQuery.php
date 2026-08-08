<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Queries;

use ByRcsc\LaravelCustomerHealth\Data\MorphIdentity;
use ByRcsc\LaravelCustomerHealth\Exceptions\UnresolvableSubjectException;
use ByRcsc\LaravelCustomerHealth\Models\Milestone;
use ByRcsc\LaravelCustomerHealth\Models\ProductEventRecord;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

final readonly class KnownSubjectsQuery
{
    public function __construct(private ?MorphIdentity $subject = null) {}

    public function builder(): Builder
    {
        $event = new ProductEventRecord;
        $milestone = new Milestone;
        $connection = $event->getConnection();
        $events = $connection->table($event->getTable())->select(['subject_type', 'subject_id']);
        $milestones = $connection->table($milestone->getTable())->select(['subject_type', 'subject_id']);
        $query = $connection->query()
            ->fromSub($events->union($milestones), 'known_subjects')
            ->select(['subject_type', 'subject_id'])
            ->distinct()
            ->orderBy('subject_type')
            ->orderBy('subject_id');

        if ($this->subject !== null) {
            $query->where('subject_type', $this->subject->type)
                ->where('subject_id', $this->subject->id);
        }

        return $query;
    }

    public function count(): int
    {
        return $this->builder()->count();
    }

    /** @param Closure(Collection<int, MorphIdentity>): void $callback */
    public function eachChunk(int $size, Closure $callback): void
    {
        $last = null;

        do {
            $query = $this->builder();

            if ($last instanceof MorphIdentity) {
                $query->where(function (Builder $query) use ($last): void {
                    $query->where('subject_type', '>', $last->type)
                        ->orWhere(function (Builder $query) use ($last): void {
                            $query->where('subject_type', $last->type)
                                ->where('subject_id', '>', $last->id);
                        });
                });
            }

            $rows = $query->limit($size)->get();
            $references = $rows->map(fn (object $row): MorphIdentity => $this->identityFromRow($row));

            if ($references->isEmpty()) {
                break;
            }

            $callback($references);
            $last = $references->last();
        } while ($references->count() === $size);
    }

    private function identityFromRow(object $row): MorphIdentity
    {
        $attributes = get_object_vars($row);
        $type = $attributes['subject_type'] ?? null;
        $id = $attributes['subject_id'] ?? null;

        if (! is_string($type) || (! is_string($id) && ! is_int($id))) {
            throw UnresolvableSubjectException::forIdentity('unknown', 'unknown');
        }

        return new MorphIdentity($type, is_int($id) ? (string) $id : $id);
    }
}
