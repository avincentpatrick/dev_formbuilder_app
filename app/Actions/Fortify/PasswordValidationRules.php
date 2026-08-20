<?php

namespace App\Actions\Fortify;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, Rule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return [...$this->passwordRulesUnconfirmed(), 'confirmed'];
    }

    /**
     * The same policy WITHOUT the confirmation field, for a single-field surface.
     *
     * ── WHY THIS METHOD EXISTS RATHER THAN A SECOND COPY ────────────────────────────────────────────────
     * `InvitationController::registerInvitedPlaceholder()` (named `prepareAcceptingUser()` until M8 split
     * the identity fork out of it) declared its own `['required', 'string',
     * Password::default()]` because its accept page has ONE password box, so `'confirmed'` genuinely cannot
     * be inherited — a real difference, not an oversight. But an inline copy makes the divergence invisible:
     * a future `->max()`, a custom rule, or a `'string'` becoming something else reaches three surfaces and
     * silently misses the fourth. J3a's four character classes are the near miss that made the point — they
     * arrive on the invite path only because they live inside `Password::default()`.
     *
     * Splitting the trait keeps the difference where a reader can see it: the divergence is now the METHOD
     * NAME at the call site, and everything the two surfaces share moves together by construction.
     * `AuthenticationTest` pins that these two differ by exactly `'confirmed'` and nothing else.
     *
     * @return array<int, Rule|array<mixed>|string>
     */
    protected function passwordRulesUnconfirmed(): array
    {
        return ['required', 'string', Password::default()];
    }
}
