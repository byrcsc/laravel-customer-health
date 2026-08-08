<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Commands;

use ByRcsc\LaravelCustomerHealth\Contracts\Trackable;
use ByRcsc\LaravelCustomerHealth\CustomerHealthManager;
use ByRcsc\LaravelCustomerHealth\Data\MorphIdentity;
use ByRcsc\LaravelCustomerHealth\Exceptions\InvalidScoreDefinitionException;
use ByRcsc\LaravelCustomerHealth\Exceptions\UnresolvableSubjectException;
use ByRcsc\LaravelCustomerHealth\Queries\KnownSubjectsQuery;
use ByRcsc\LaravelCustomerHealth\Registry\HealthScoreRegistry;
use ByRcsc\LaravelCustomerHealth\Scoring\HealthScore;
use ByRcsc\LaravelCustomerHealth\Scoring\WindowedSignal;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

final class RecomputeHealthScoresCommand extends Command
{
    protected $signature = 'customer-health:recompute
        {--score= : Limit recomputation to one registered score key}
        {--subject= : Limit recomputation to one Type:id subject identity}
        {--chunk=500 : Number of known subjects to load at once}';

    protected $description = 'Recompute registered customer health scores for every known subject';

    public function handle(HealthScoreRegistry $scores, CustomerHealthManager $customerHealth): int
    {
        try {
            $chunk = $this->chunkSize();
            $subjectFilter = $this->subjectFilter();
            $definitions = $this->scoreDefinitions($scores);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->warnWhenRetentionIsShorterThanScoreWindows($scores);

        $subjects = new KnownSubjectsQuery($subjectFilter);
        $this->output->progressStart($subjects->count() * count($definitions));
        $failureCount = 0;
        $computed = 0;

        $subjects->eachChunk($chunk, function (Collection $references) use (
            $customerHealth,
            $definitions,
            &$computed,
            &$failureCount,
        ): void {
            foreach ($references as $reference) {
                $advanced = 0;
                $label = $reference->type.':'.$reference->id;

                try {
                    $subject = $reference->resolve();

                    if (! $subject instanceof Trackable) {
                        throw UnresolvableSubjectException::forIdentity($reference->type, $reference->id);
                    }

                    foreach ($definitions as $definition) {
                        $customerHealth->compute($subject, $definition::name());
                        $computed++;
                        $advanced++;
                        $this->output->progressAdvance();
                    }
                } catch (Throwable $exception) {
                    $failureCount++;
                    $this->output->progressAdvance(count($definitions) - $advanced);
                    $this->newLine();
                    $this->error("Failed [{$label}]: {$exception->getMessage()}");
                }
            }
        });

        $this->output->progressFinish();
        $this->info("Recomputed {$computed} health score(s). {$failureCount} subject(s) failed.");

        return $failureCount === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function warnWhenRetentionIsShorterThanScoreWindows(HealthScoreRegistry $scores): void
    {
        $retention = config('customer-health.retention_days');
        if (! is_int($retention) || $retention < 0) {
            return;
        }

        $longestWindow = 0;
        foreach ($scores->all() as $score) {
            foreach ($scores->weightedSignals($score) as ['signal' => $signal]) {
                if ($signal instanceof WindowedSignal) {
                    $longestWindow = max($longestWindow, $signal->windowDays());
                }
            }
        }

        if ($retention < $longestWindow) {
            $this->warn(
                "Raw event retention [{$retention} days] is shorter than the longest registered signal window [{$longestWindow} days]; recomputed activity scores may ignore pruned events.",
            );
        }
    }

    private function chunkSize(): int
    {
        $chunk = $this->input->getOption('chunk');

        if (is_int($chunk) && $chunk > 0) {
            return $chunk;
        }

        if (! is_string($chunk) || filter_var($chunk, FILTER_VALIDATE_INT) === false || (int) $chunk < 1) {
            throw InvalidScoreDefinitionException::invalid('The --chunk option must be a positive integer.');
        }

        return (int) $chunk;
    }

    private function subjectFilter(): ?MorphIdentity
    {
        $subject = $this->input->getOption('subject');
        if ($subject === null) {
            return null;
        }

        if (! is_string($subject) || ! str_contains($subject, ':')) {
            throw InvalidScoreDefinitionException::invalid('The --subject option must use Type:id format.');
        }

        [$type, $id] = explode(':', $subject, 2);
        if ($type === '' || $id === '') {
            throw InvalidScoreDefinitionException::invalid('The --subject option must use Type:id format.');
        }

        return new MorphIdentity($type, $id);
    }

    /** @return list<HealthScore> */
    private function scoreDefinitions(HealthScoreRegistry $scores): array
    {
        $score = $this->input->getOption('score');

        if ($score === null) {
            return $scores->all();
        }

        if (! is_string($score) || $score === '') {
            throw InvalidScoreDefinitionException::invalid('The --score option must be a registered score key.');
        }

        return [$scores->resolve($score)];
    }
}
