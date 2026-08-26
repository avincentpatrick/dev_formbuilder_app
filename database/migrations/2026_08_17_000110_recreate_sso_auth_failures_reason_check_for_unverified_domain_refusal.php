<?php

declare(strict_types=1);

use App\Enums\SsoFailureReason;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M18 — widen `sso_auth_failures.reason` for the refusal that closes the domain-trust gap.
 *
 * `SsoFailureReason::DomainNotVerified` is recorded when an assertion names an address whose email domain
 * this workspace has not proven it controls. `SsoUserProvisioner` now refuses that before it asks any
 * question about the account behind the address — see `SsoAuthenticationException::domainNotVerified()` for
 * what the refusal closes and why its position in the sequence is load-bearing.
 *
 * ⚠️ THE CHECK IS WHY THIS FILE EXISTS AT ALL, AND THIS IS THE FOURTH TIME IT HAS BEEN THE REASON (M1's
 * `2026_08_17_000104`, M2's `…000105`, M9's `…000108`, now this). `2026_08_15_000002` CHECK-constrains
 * `reason` to `SsoFailureReason::values()` so the failures panel's vocabulary cannot drift from the enum.
 * Adding a case without widening the constraint would turn the new refusal into a 23514 at the moment it
 * fires — the guard throwing WHILE BEING RECORDED, on the one endpoint anyone on the internet can post to,
 * which converts a closed hole into a 500 on an unauthenticated route.
 *
 * ⚠️ AND THIS ONE IS THE FIRST WHERE THAT 23514 WOULD HAVE BEEN A **DENIAL OF SERVICE FOR THE HONEST CASE
 * TOO**, which is worth stating because the pattern has looked like ceremony three times running. The three
 * earlier refusals fire only on an attempted takeover. This one fires on every ordinary sign-in at a
 * workspace that has not yet verified its domain — i.e. on every existing deployment, on the first new
 * joiner after deploy. A missing widening would have made the commonest path a 500 rather than a rare one.
 *
 * `up()` regenerates from the CURRENT enum rather than a frozen list, so a later case cannot be silently
 * dropped by a chained recreation; only `down()` names the frozen pre-M18 vocabulary, because that is the one
 * list this file is the authority on.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'sso_auth_failures_reason_check';

    /** The 19 reasons that existed before M18. Named only here, and only for the reversal. */
    private const PRE_M18_REASONS = [
        'response_missing', 'response_too_large', 'response_malformed',
        'unsolicited_assertion', 'unknown_request', 'request_replayed', 'assertion_replayed',
        'idp_certificate_unusable', 'invalid_assertion', 'assertion_outside_conditions',
        'no_email', 'jit_disabled', 'existing_account_not_member', 'established_identity_not_joined',
        'membership_suspended', 'seat_quota_exhausted',
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
        // this table is a bounded diagnostic surface and never a source of truth for anything.
        DB::table('sso_auth_failures')
            ->where('reason', SsoFailureReason::DomainNotVerified->value)
            ->delete();

        $this->recreate(self::PRE_M18_REASONS);
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
