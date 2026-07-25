<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Enums\FormScheduleState;
use App\Models\Form;
use Carbon\CarbonInterface;

/**
 * Pure schedule arithmetic for scheduled forms (Increment H12a) — no DB, no side effects, so it is the one
 * place the config writer, the state-flip sweep, the acceptance guard and the runtime presenter all agree on
 * "when is this form open". Every comparison is against absolute `timestamptz` instants; `forms.timezone` is
 * authoring/display metadata and is deliberately never consulted here.
 *
 * Two distinct notions live side by side and must not be conflated:
 *  - {@see initialState()} drives the persisted {@see FormScheduleState} (time window ONLY — the sweep's
 *    once-only emission key; capacity is irrelevant to it).
 *  - {@see acceptance()} is the LIVE runtime label the presenter shows (includes the response cap).
 */
final class FormSchedule
{
    /** The form has an open time and it has not arrived yet. */
    public static function isBeforeOpen(Form $form, CarbonInterface $now): bool
    {
        return $form->opens_at !== null && $now->lessThan($form->opens_at);
    }

    /** The form has a close time and it has passed. */
    public static function isAfterClose(Form $form, CarbonInterface $now): bool
    {
        return $form->closes_at !== null && $now->greaterThanOrEqualTo($form->closes_at);
    }

    /**
     * The `schedule_state` a form should hold given the wall clock, or null when it has no schedule at all
     * (neither time set) so the sweep skips it. Computed by the config writer on every schedule change and
     * advanced from there by the sweep.
     */
    public static function initialState(Form $form, CarbonInterface $now): ?FormScheduleState
    {
        if ($form->opens_at === null && $form->closes_at === null) {
            return null;
        }

        if (self::isAfterClose($form, $now)) {
            return FormScheduleState::Closed;
        }

        if (self::isBeforeOpen($form, $now)) {
            return FormScheduleState::Scheduled;
        }

        return FormScheduleState::Open;
    }

    /**
     * The respondent-facing acceptance label for the runtime (H12b consumes it). `$finalizedCount` is the
     * live non-draft submission count, supplied only when the form has a `max_responses` cap (otherwise null).
     * Precedence: an explicit close beats a pending open beats a full cap.
     */
    public static function acceptance(Form $form, ?int $finalizedCount, CarbonInterface $now): string
    {
        if (self::isAfterClose($form, $now)) {
            return 'closed';
        }

        if (self::isBeforeOpen($form, $now)) {
            return 'opens_soon';
        }

        if ($form->max_responses !== null && $finalizedCount !== null && $finalizedCount >= $form->max_responses) {
            return 'capacity_reached';
        }

        return 'open';
    }
}
