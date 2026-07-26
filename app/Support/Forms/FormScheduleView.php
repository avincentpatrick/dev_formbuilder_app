<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Models\Form;
use App\Services\Submissions\EncodeFormPresenter;
use App\Services\Submissions\PublicFormPresenter;
use Carbon\CarbonImmutable;

/**
 * Shapes a form's schedule window + response cap into the wire block a runtime presenter emits (Increment
 * H12b) — the ONE place the guest {@see PublicFormPresenter} and the manual-encode
 * {@see EncodeFormPresenter} agree on that shape, so the two surfaces can never
 * drift. Pure: the caller passes the live non-draft submission COUNT it read under RLS (or null for an
 * uncapped form, so an uncapped form costs no query); the live `acceptance` label comes from
 * {@see FormSchedule::acceptance()} and `remaining` is the cap headroom (null when uncapped).
 */
final class FormScheduleView
{
    /**
     * @return array{opens_at: ?string, closes_at: ?string, timezone: string, max_responses: ?int, acceptance: string, remaining: ?int}
     */
    public static function present(Form $form, ?int $finalizedCount): array
    {
        $cap = $form->max_responses;
        $count = $cap === null ? null : $finalizedCount;
        $remaining = ($cap === null || $count === null) ? null : (int) max(0, $cap - $count);

        return [
            'opens_at' => $form->opens_at?->toIso8601String(),
            'closes_at' => $form->closes_at?->toIso8601String(),
            'timezone' => $form->timezone,
            'max_responses' => $cap,
            'acceptance' => FormSchedule::acceptance($form, $count, CarbonImmutable::now()),
            'remaining' => $remaining,
        ];
    }
}
