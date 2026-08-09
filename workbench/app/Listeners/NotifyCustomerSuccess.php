<?php

declare(strict_types=1);

namespace Workbench\App\Listeners;

use ByRcsc\LaravelCustomerHealth\Events\HealthStateChanged;
use Illuminate\Support\Facades\Log;

final class NotifyCustomerSuccess
{
    public function handle(HealthStateChanged $event): void
    {
        if ($event->to === 'at_risk') {
            Log::warning('Customer entered an at-risk health state.', [
                'subject_type' => $event->record->subject_type,
                'subject_id' => $event->record->subject_id,
                'score' => $event->record->score,
            ]);
        }
    }
}
