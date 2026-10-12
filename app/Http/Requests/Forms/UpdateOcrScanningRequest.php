<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Services\Forms\FormService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Let a form accept scans of its printed paper, or stop it (M129 — single-form OCR groundwork 2).
 *
 * Its own request — deliberately NOT folded into {@see FormMetadataRequest} — mirroring the
 * {@see UpdateSaveResumeRequest}, {@see UpdatePageModeRequest} and {@see AssignFormScopeRequest} precedents:
 * the write is a dedicated {@see FormService::setOcrScanning} and never mass-assignment, because
 * `allow_ocr_single` IS in `Form::$fillable`.
 *
 * The workspace's half of the gate is at the route, and in save-and-resume's shape plus one: `can:update,form`,
 * then the `ocr_single` module toggle BEFORE the plan, so a workspace that switched scanning off is told so
 * rather than told to upgrade — the order the scan routes themselves use.
 *
 * ⚠️ The payload key is the COLUMN name while the request, route and section are named for the SETTING, for
 * `UpdatePageModeRequest`'s reason: the rule, the service and the column then read as one.
 *
 * ── THE PUBLIC LINK'S PAPER COPIES (M165, `R-7173c02c`, `D106`) ───────────────────────────────────────
 * The same section also lets the public link take a filled paper copy, with the two limits the user chose: a
 * per-device window of 1, 2, 5 or 10 minutes (10 by default, never longer) and a daily cap of 1 to 100. The three
 * keys travel together or not at all, so the scanning switch alone still saves on its own, as it always has.
 */
final class UpdateOcrScanningRequest extends FormRequest
{
    /** The windows `forms_guest_ocr_window_minutes_check` accepts, shortest first. */
    public const GUEST_WINDOWS = [1, 2, 5, 10];

    /** The daily cap's bounds — `forms_guest_ocr_daily_cap_check`. */
    public const GUEST_DAILY_CAP_MAX = 100;

    public function authorize(): bool
    {
        return true;   // route middleware (can:update,form, module:ocr_single, feature:ocr_single) owns authorization
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'allow_ocr_single' => ['required', 'boolean'],
            'allow_guest_ocr' => ['required_with:guest_ocr_window_minutes,guest_ocr_daily_cap', 'boolean'],
            'guest_ocr_window_minutes' => ['required_with:allow_guest_ocr', 'integer', Rule::in(self::GUEST_WINDOWS)],
            'guest_ocr_daily_cap' => ['required_with:allow_guest_ocr', 'integer', 'min:1', 'max:'.self::GUEST_DAILY_CAP_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'guest_ocr_window_minutes.in' => 'Choose 1, 2, 5 or 10 minutes.',
            'guest_ocr_daily_cap.min' => 'Allow at least 1 paper copy a day.',
            'guest_ocr_daily_cap.max' => 'Allow at most '.self::GUEST_DAILY_CAP_MAX.' paper copies a day.',
        ];
    }

    /**
     * The public link's paper-copy settings when this save carries them, or null when it is the scanning switch alone.
     *
     * @return array{allow_guest_ocr: bool, guest_ocr_window_minutes: int, guest_ocr_daily_cap: int}|null
     */
    public function guestSettings(): ?array
    {
        if (! $this->has('allow_guest_ocr')) {
            return null;
        }

        return [
            'allow_guest_ocr' => $this->boolean('allow_guest_ocr'),
            'guest_ocr_window_minutes' => $this->integer('guest_ocr_window_minutes'),
            'guest_ocr_daily_cap' => $this->integer('guest_ocr_daily_cap'),
        ];
    }
}
