<?php

declare(strict_types=1);

namespace App\Exceptions\Settings;

use App\Exceptions\Forms\BuilderConflictException;
use App\Services\Admin\SuperAdminService;
use App\Services\Settings\PlatformSettings;
use RuntimeException;

/**
 * Optimistic-concurrency drift on the super-admin console's platform settings (M103, R-2173fe28).
 *
 * The console PATCHed the three platform keys carrying the {@see PlatformSettings::fingerprint()} token it
 * was rendered with, and the stored settings have since changed. The write is refused whole, inside the
 * elevated transaction that read the current state, so a stale tab cannot revert a setting another operator
 * has just changed.
 *
 * ── WHY THIS EXISTS AT ALL, WHICH IS NOT OBVIOUS FROM THE SYMPTOM ──────────────────────────────────────
 * `D31` — *"the testing server is invitation-only"* — is recorded as **applied, not built**: its entire
 * enforcement is `registration.open_signup` being switched off in this console, and that key
 * {@see App\Enums\SettingKey::default()}s to `true`. The console posts all three fields on every Save, so
 * before this check a tab opened while signup was still open would silently re-open public registration the
 * next time anyone used it to edit the maintenance notice — reporting success, with the same toast and the
 * same 303, and leaving the operator no signal at all. The audit log recorded the reversal faithfully;
 * nobody was reading it in the second that mattered.
 *
 * ── WHY NOT `form.isDirty`, WHICH LOOKS SUFFICIENT AND IS NOT ──────────────────────────────────────────
 * Inertia's dirty check compares the form against THE PAGE'S OWN INITIAL PROPS, so the stale tab is
 * legitimately dirty — it really did edit the notice — and would post the stale toggle anyway. A
 * server-side per-key no-op skip fails for the same reason: the stale value genuinely differs from the
 * stored one, so it is not a no-op, it is a revert. Only a token carried from the read can tell the two
 * apart.
 *
 * ── RENDERED AS 422, NOT 409, AND THAT IS A SURFACE DECISION RATHER THAN A DISAGREEMENT ────────────────
 * {@see BuilderConflictException} is this repository's other optimistic-concurrency refusal and answers
 * 409 with the fresh row attached, because the builder is a JSON client with a ConflictDialog that
 * reconciles two versions. This console is an Inertia form with one Save button: `useForm` understands a
 * 422 error bag and a redirect, and has nothing to do with a 409 body. The controller therefore maps this
 * to a redirect carrying an error, which re-runs the index action and re-renders the page from the CURRENT
 * settings and a fresh token. Same rule, two surfaces, each answered in its own dialect.
 */
final class PlatformSettingsConflictException extends RuntimeException
{
    /**
     * @param  array<string, bool|string>  $current  the platform settings as they actually stand, read on
     *                                               the elevated connection inside the refused transaction
     *                                               ({@see SuperAdminService::platformValues()}).
     */
    public function __construct(public readonly array $current)
    {
        parent::__construct(
            'These settings were changed elsewhere since this page was loaded. '
            .'The current values are shown below — review them and save again.'
        );
    }
}
