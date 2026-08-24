<?php

declare(strict_types=1);

use App\Enums\SsoFailureReason;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M9 — widen `sso_auth_failures.reason` for the refusal that closes the invitation-adoption takeover.
 *
 * `SsoFailureReason::EstablishedIdentityNotJoined` is recorded when an IdP asserts an address whose account
 * already exists AND has an established identity, while this workspace holds only an `Invited`, `Declined`
 * or `Removed` row for it. `SsoUserProvisioner` now refuses that outright — an invitation names an ADDRESS,
 * which establishes nothing about who is behind one — see
 * `SsoAuthenticationException::establishedIdentityNotJoined()` for the takeover chain it closes and for the
 * alternative that was rejected.
 *
 * ⚠️ THE CHECK IS WHY THIS FILE EXISTS AT ALL, AND THIS IS THE THIRD TIME IT HAS BEEN THE REASON (M1's
 * `2026_08_17_000104`, M2's `…000105`, now this). `2026_08_15_000002` CHECK-constrains `reason` to
 * `SsoFailureReason::values()` so the failures panel's vocabulary cannot drift from the enum. Adding a case
 * without widening the constraint would turn the new refusal into a 23514 at the moment it fires — i.e. the
 * guard would throw WHILE BEING RECORDED, on the one endpoint anyone on the internet can post to, which
 * turns a closed hole into a 500 on an unauthenticated route.
 *
 * `up()` regenerates from the CURRENT enum rather than a frozen list, so a later case cannot be silently
 * dropped by a chained recreation; only `down()` names the frozen pre-M9 vocabulary, because that is the one
 * list this file is the authority on.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'sso_auth_failures_reason_check';

    /** The 18 reasons that existed before M9. Named only here, and only for the reversal. */
    private const PRE_M9_REASONS = [
        'response_missing', 'response_too_large', 'response_malformed',
        'unsolicited_assertion', 'unknown_request', 'request_replayed', 'assertion_replayed',
        'idp_certificate_unusable', 'invalid_assertion', 'assertion_outside_conditions',
        'no_email', 'jit_disabled', 'existing_account_not_member', 'membership_suspended',
        'seat_quota_exhausted',
        'step_up_not_forced', 'step_up_unknown_subject', 'step_up_subject_mismatch',
    ];

    public function up(): void
    {
        $this->recreate(SsoFailureReason::values());
    }

    public function down(): void
    {
        // Rows carrying the withdrawn reason would violate the narrowed constraint. There is no sensible
        // re-classification of them — the reason IS the record — so they are removed, which is safe because
        // this table is an append-only diagnostic surface and never a source of truth for anything.
        DB::table('sso_auth_failures')
            ->where('reason', SsoFailureReason::EstablishedIdentityNotJoined->value)
            ->delete();

        $this->recreate(self::PRE_M9_REASONS);
    }

    /** @param  list<string>  $reasons */
    private function recreate(array $reasons): void
    {
        $list = implode(', ', array_map(static fn (string $value): string => "'".$value."'", $reasons));

        DB::statement('ALTER TABLE sso_auth_failures DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        DB::statement(
            'ALTER TABLE sso_auth_failures ADD CONSTRAINT '.self::CONSTRAINT
            ." CHECK (reason IN ({$list}))"
        );
    }
};
