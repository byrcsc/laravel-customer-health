<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Actions;

use ByRcsc\LaravelCustomerHealth\Contracts\Trackable;
use ByRcsc\LaravelCustomerHealth\Data\MorphIdentity;
use ByRcsc\LaravelCustomerHealth\Events\HealthScoreComputed;
use ByRcsc\LaravelCustomerHealth\Events\HealthStateChanged;
use ByRcsc\LaravelCustomerHealth\Exceptions\InvalidSignalValueException;
use ByRcsc\LaravelCustomerHealth\Models\HealthScoreRecord;
use ByRcsc\LaravelCustomerHealth\Registry\HealthScoreRegistry;
use ByRcsc\LaravelCustomerHealth\Support\ScoreComputationLock;
use ByRcsc\LaravelCustomerHealth\ValueObjects\ScoreResult;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;

final readonly class ComputeHealthScore
{
    public function __construct(
        private HealthScoreRegistry $scores,
        private Dispatcher $dispatcher,
        private DatabaseManager $database,
        private ScoreComputationLock $lock,
    ) {}

    public function handle(Trackable $subject, ?string $score = null): ScoreResult
    {
        $definition = $this->scores->resolve($score);
        $weightedSignals = $this->scores->weightedSignals($definition);
        $totalWeight = array_sum(array_column($weightedSignals, 'weight'));
        $breakdown = [];
        $total = 0.0;

        foreach ($weightedSignals as ['signal' => $signal, 'weight' => $weight]) {
            $raw = $signal->evaluate($subject);

            if ($raw < 0 || $raw > 100) {
                throw InvalidSignalValueException::outsideRange($signal::class, $raw);
            }

            $normalizedWeight = $weight / $totalWeight;
            $contribution = $raw * $normalizedWeight;
            $total += $contribution;
            $breakdown[] = [
                'signal' => $signal::class,
                'raw' => $raw,
                'weight' => round($normalizedWeight, 6),
                'contribution' => round($contribution, 6),
            ];
        }

        $identity = MorphIdentity::from($subject, 'subject');
        $value = (int) round($total);
        $state = $this->scores->stateFor($definition, $value);
        $connection = (new HealthScoreRecord)->getConnection();
        $connectionName = $connection->getName() ?? $this->database->getDefaultConnection();

        [$record, $previousState] = $connection->transaction(function () use (
            $breakdown,
            $connectionName,
            $definition,
            $identity,
            $state,
            $value,
        ): array {
            $lockKey = json_encode([$identity->type, $identity->id, $definition::name()], JSON_THROW_ON_ERROR);
            $this->lock->acquire($this->database->connection($connectionName), $lockKey);
            $computedAt = CarbonImmutable::now('UTC');
            $previousState = HealthScoreRecord::on($connectionName)
                ->where('subject_type', $identity->type)
                ->where('subject_id', $identity->id)
                ->where('score', $definition::name())
                ->latest('computed_at')
                ->latest('id')
                ->value('state');
            $record = HealthScoreRecord::on($connectionName)->create([
                'subject_type' => $identity->type,
                'subject_id' => $identity->id,
                'score' => $definition::name(),
                'value' => $value,
                'state' => $state,
                'breakdown' => $breakdown,
                'computed_at' => $computedAt,
            ]);

            return [$record, is_string($previousState) ? $previousState : null];
        });

        $result = ScoreResult::fromRecord($record);
        $connection->afterCommit(function () use ($previousState, $record, $result): void {
            $this->dispatcher->dispatch(new HealthScoreComputed($record, $result));

            if ($previousState !== $result->state) {
                $this->dispatcher->dispatch(new HealthStateChanged($record, $previousState, $result->state));
            }
        });

        return $result;
    }
}
