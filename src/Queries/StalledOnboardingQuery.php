<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Queries;

use ByRcsc\LaravelCustomerHealth\Data\MorphIdentity;
use ByRcsc\LaravelCustomerHealth\Models\Milestone;
use ByRcsc\LaravelCustomerHealth\Registry\ChecklistRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final readonly class StalledOnboardingQuery
{
    public function __construct(private int $days, private ChecklistRegistry $checklists) {}

    /** @return Collection<int, MorphIdentity> */
    public function get(): Collection
    {
        $cutoff = CarbonImmutable::now('UTC')->subDays($this->days);
        /** @var array<string, MorphIdentity> $references */
        $references = [];

        foreach ($this->checklists->all() as $checklist) {
            $stepNames = $checklist->stepNames();
            $rows = Milestone::query()
                ->whereIn('name', $stepNames)
                ->groupBy(['subject_type', 'subject_id'])
                ->havingRaw('COUNT(DISTINCT name) < ?', [count($stepNames)])
                ->havingRaw('MAX(occurred_at) < ?', [$cutoff])
                ->select(['subject_type', 'subject_id'])
                ->get();

            foreach ($rows as $row) {
                $identity = new MorphIdentity($row->subject_type, $row->subject_id);
                $references[$identity->key()] = $identity;
            }
        }

        return collect(array_values($references));
    }
}
