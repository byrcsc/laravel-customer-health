<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Actions;

use ByRcsc\LaravelCustomerHealth\Events\OnboardingCompleted;
use ByRcsc\LaravelCustomerHealth\Events\OnboardingStepCompleted;
use ByRcsc\LaravelCustomerHealth\Models\Milestone;
use ByRcsc\LaravelCustomerHealth\Registry\ChecklistRegistry;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;

final readonly class RecordOnboardingProgress
{
    public function __construct(
        private ChecklistRegistry $checklists,
        private Dispatcher $dispatcher,
        private DatabaseManager $database,
    ) {}

    public function afterCommit(Milestone $milestone, bool $stepWasInserted): void
    {
        $connection = $milestone->getConnection();
        $connectionName = $connection->getName() ?? $this->database->getDefaultConnection();

        $connection->afterCommit(function () use ($connectionName, $milestone, $stepWasInserted): void {
            foreach ($this->reconcile($milestone, $stepWasInserted, $connectionName) as $event) {
                $this->dispatcher->dispatch($event);
            }
        });
    }

    /** @return list<OnboardingStepCompleted|OnboardingCompleted> */
    private function reconcile(Milestone $milestone, bool $stepWasInserted, string $connectionName): array
    {
        $events = [];

        foreach ($this->checklists->forEvent($milestone->name) as $checklist) {
            if ($stepWasInserted) {
                $events[] = new OnboardingStepCompleted($milestone, $checklist::name(), $checklist->stepForName($milestone->name));
            }
            $stepNames = $checklist->stepNames();
            $completed = Milestone::on($connectionName)
                ->where('subject_type', $milestone->subject_type)
                ->where('subject_id', $milestone->subject_id)
                ->whereIn('name', $stepNames)
                ->distinct()
                ->count('name');

            if ($completed !== count($stepNames)) {
                continue;
            }

            $name = 'onboarding:'.$checklist::name();
            $attributes = [
                'subject_type' => $milestone->subject_type,
                'subject_id' => $milestone->subject_id,
                'name' => $name,
                'actor_type' => $milestone->actor_type,
                'actor_id' => $milestone->actor_id,
                'occurred_at' => $milestone->occurred_at,
                'created_at' => now('UTC'),
            ];

            if (Milestone::on($connectionName)->insertOrIgnore($attributes) === 1) {
                $completion = Milestone::on($connectionName)
                    ->where('subject_type', $milestone->subject_type)
                    ->where('subject_id', $milestone->subject_id)
                    ->where('name', $name)
                    ->sole();
                $events[] = new OnboardingCompleted($completion, $checklist::name());
            }
        }

        return $events;
    }
}
