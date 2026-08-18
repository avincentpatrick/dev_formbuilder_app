<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Enums\NotificationType;
use App\Services\Notifications\NotificationPreferenceResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One row of data-dictionary §23 — the acting user's channels for ONE notification type (Increment I4).
 *
 * ── Every rule is `required`, the deliberate opposite of UpdateAppearanceRequest ────────────────────────
 * That request's four axes are independent settings on one row, so each is `sometimes` and the top-nav
 * quick toggle can send `theme_mode` alone without disturbing the rest. Here the two booleans ARE the row:
 * `notification_preferences` columns default to `true`/`true`, which DISAGREES with
 * {@see NotificationType::SubmissionReceived}'s email default of `false`, so a partial write that leaned
 * on the column default would silently opt a user INTO the one type the PRD says to keep quiet.
 * {@see NotificationPreferenceResolver::set()} spells the same rule on the write side; this makes it
 * impossible to reach that method with half an answer.
 */
final class UpdateNotificationPreferenceRequest extends FormRequest
{
    /** Route `auth` plus strict RLS keyed on `user_id`: a user can only ever write their own row. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'notification_type' => ['required', Rule::enum(NotificationType::class)],
            'in_app_enabled' => ['required', 'boolean'],
            'email_enabled' => ['required', 'boolean'],
        ];
    }

    public function type(): NotificationType
    {
        return NotificationType::from((string) $this->validated('notification_type'));
    }

    /**
     * The values that actually reach the columns.
     *
     * The email flag is FORCED, not validated away, for the two types whose email is owned by the emitting
     * site ({@see NotificationType::honorsEmailPreference()} — `export_ready` and `webhook_failed`). A 422
     * was rejected: the card renders those controls as unavailable, so a differing value can only come from
     * a stale tab or a hand-built request, and refusing a control the UI never offered is a worse answer
     * than ignoring it. Storing the submitted value was rejected too — the RESOLVER already ignores it on
     * read, so the column would hold a number nothing believes, which is exactly the shape a future reader
     * mistakes for the truth.
     *
     * @return array{in_app: bool, email: bool}
     */
    public function toChannels(): array
    {
        $type = $this->type();

        return [
            'in_app' => $this->boolean('in_app_enabled'),
            'email' => $type->honorsEmailPreference()
                ? $this->boolean('email_enabled')
                : $type->defaultEmail(),
        ];
    }
}
