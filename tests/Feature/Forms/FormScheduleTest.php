<?php

declare(strict_types=1);

use App\Enums\FormScheduleState;
use App\Models\Form;
use App\Support\Forms\FormSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

// Pure schedule arithmetic (Increment H12a). No DB rows are touched (unsaved models only), but this lives in
// Feature, not Unit, because Eloquent's datetime cast needs the booted app's connection resolver to format the
// opens_at/closes_at values. Every check is against absolute instants; forms.timezone is never consulted.
// Pins the boolean helpers + the two derived notions (persisted schedule_state via initialState, the live
// runtime label via acceptance), including the open-ended and capacity-boundary cases.

$now = CarbonImmutable::parse('2026-07-25 12:00:00');

function makeScheduleForm(?CarbonInterface $opensAt, ?CarbonInterface $closesAt, ?int $maxResponses = null): Form
{
    $form = new Form;
    $form->opens_at = $opensAt;
    $form->closes_at = $closesAt;
    $form->max_responses = $maxResponses;

    return $form;
}

it('detects the pre-open window', function () use ($now): void {
    expect(FormSchedule::isBeforeOpen(makeScheduleForm($now->addHour(), null), $now))->toBeTrue()
        ->and(FormSchedule::isBeforeOpen(makeScheduleForm($now->subHour(), null), $now))->toBeFalse()
        ->and(FormSchedule::isBeforeOpen(makeScheduleForm(null, null), $now))->toBeFalse();
});

it('detects the post-close window (inclusive of the close instant)', function () use ($now): void {
    expect(FormSchedule::isAfterClose(makeScheduleForm(null, $now->subHour()), $now))->toBeTrue()
        ->and(FormSchedule::isAfterClose(makeScheduleForm(null, $now), $now))->toBeTrue() // >= close
        ->and(FormSchedule::isAfterClose(makeScheduleForm(null, $now->addHour()), $now))->toBeFalse()
        ->and(FormSchedule::isAfterClose(makeScheduleForm(null, null), $now))->toBeFalse();
});

it('derives the initial schedule_state, null when there is no schedule', function () use ($now): void {
    expect(FormSchedule::initialState(makeScheduleForm(null, null), $now))->toBeNull()
        ->and(FormSchedule::initialState(makeScheduleForm($now->addHour(), null), $now))->toBe(FormScheduleState::Scheduled)
        ->and(FormSchedule::initialState(makeScheduleForm($now->subHour(), $now->addHour()), $now))->toBe(FormScheduleState::Open)
        ->and(FormSchedule::initialState(makeScheduleForm(null, $now->addHour()), $now))->toBe(FormScheduleState::Open)
        ->and(FormSchedule::initialState(makeScheduleForm($now->subDay(), $now->subHour()), $now))->toBe(FormScheduleState::Closed);
});

it('labels runtime acceptance: close beats pre-open beats full cap', function () use ($now): void {
    // closed wins over everything
    expect(FormSchedule::acceptance(makeScheduleForm($now->addHour(), $now->subHour()), null, $now))->toBe('closed')
        // pre-open
        ->and(FormSchedule::acceptance(makeScheduleForm($now->addHour(), null), null, $now))->toBe('opens_soon')
        // within window, uncapped
        ->and(FormSchedule::acceptance(makeScheduleForm(null, null), null, $now))->toBe('open')
        // within window, capped but with headroom
        ->and(FormSchedule::acceptance(makeScheduleForm(null, null, 5), 2, $now))->toBe('open')
        // within window, cap reached
        ->and(FormSchedule::acceptance(makeScheduleForm(null, null, 5), 5, $now))->toBe('capacity_reached')
        ->and(FormSchedule::acceptance(makeScheduleForm(null, null, 5), 6, $now))->toBe('capacity_reached');
});
