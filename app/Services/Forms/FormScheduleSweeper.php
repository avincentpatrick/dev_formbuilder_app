<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FormScheduleState;
use App\Enums\FormStatus;
use App\Events\FormClosed;
use App\Events\FormOpened;
use App\Jobs\Forms\SweepTenantScheduledFormsJob;
use App\Models\Form;
use App\Services\Submissions\FormAcceptanceGuard;
use Carbon\CarbonInterface;

/**
 * The state-flip body for scheduled forms (Increment H12a), kept out of the job so it is directly
 * unit-testable. Called under one tenant's context inside {@see SweepTenantScheduledFormsJob}'s transaction, it
 * advances every published form's `schedule_state` monotonically across its open/close boundaries and emits
 * `form.opened`/`form.closed` exactly once per transition — the once-only guarantee comes from each pass
 * filtering on the PRIOR state, so a repeat tick finds nothing to flip.
 *
 * This tracks the TIME window only. Enforcement of whether a submission is accepted is LIVE and lives in
 * {@see FormAcceptanceGuard}; `schedule_state` never gates a submission (it may lag
 * this ≤5-minute sweep). Hitting `max_responses` does not flip state here.
 */
final class FormScheduleSweeper
{
    public function sweep(CarbonInterface $now): void
    {
        // Pass 1 — CLOSE FIRST. Any published form (still scheduled OR already open) whose `closes_at` has
        // passed flips to closed and emits form.closed. Running this before the open pass means a form that
        // crossed both boundaries in one tick closes directly and never emits a spurious form.opened.
        $closing = Form::query()
            ->where('status', FormStatus::Published->value)
            ->whereIn('schedule_state', [FormScheduleState::Scheduled->value, FormScheduleState::Open->value])
            ->whereNotNull('closes_at')
            ->where('closes_at', '<=', $now)
            ->lockForUpdate()
            ->get();

        foreach ($closing as $form) {
            $form->forceFill(['schedule_state' => FormScheduleState::Closed])->save();
            event(FormClosed::for($form));
        }

        // Pass 2 — OPEN. A still-scheduled published form whose `opens_at` has arrived flips to open and emits
        // form.opened. After the close pass any such form is guaranteed still within its window (else it would
        // have closed above), so no extra close guard is needed here.
        $opening = Form::query()
            ->where('status', FormStatus::Published->value)
            ->where('schedule_state', FormScheduleState::Scheduled->value)
            ->whereNotNull('opens_at')
            ->where('opens_at', '<=', $now)
            ->lockForUpdate()
            ->get();

        foreach ($opening as $form) {
            $form->forceFill(['schedule_state' => FormScheduleState::Open])->save();
            event(FormOpened::for($form));
        }
    }
}
