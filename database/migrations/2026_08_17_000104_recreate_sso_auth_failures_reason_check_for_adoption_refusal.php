<?php

declare(strict_types=1);

use App\Enums\SsoFailureReason;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M1 — widen `sso_auth_failures.reason` for the one new refusal the pre-merge review added.
 *
 * `SsoFailureReason::ExistingAccountNotMember` is the reason recorded when an IdP asserts an address that
 * already has an account on this platform which is not a member of the asserting workspace. The provisioner
 * now refuses that case outright (JIT may create an account, never adopt one) — see
 * `SsoAuthenticationException::existingAccountNotMember()` for the takeover chain it closes.
 *
 * ⚠️ THE CHECK IS WHY THIS FILE EXISTS AT ALL, AND IT IS THE `badge_earned` SHAPE EXACTLY (K1b,
 * `2026_08_17_000103`). `2026_08_15_000002` CHECK-constrains `reason` to `SsoFailureReason::values()` so the
 * failures panel's vocabulary cannot drift from the enum. Adding a case without widening the constraint
 * would turn the new refusal into a 23514 at the moment it fires — i.e. the guard would throw while being
 * recorded, on the one endpoint anyone on the internet can post to.
 *
 * `up()` regenerates from the CURRENT enum rather than a frozen list, so a later case cannot be silently
 * dropped by a chained recreation; only `down()` names the frozen pre-M1 vocabulary, because that is the
 * one list this file is the authority on.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'sso_auth_failures_reason_check';

    /** The 16 reasons that existed before M1. Named only here, and only for the reversal. */
    private const PRE_M1_REASONS = [
        'response_missing', 'response_too_large', 'response_malformed',
        'unsolicited_assertion', 'unknown_request', 'request_replayed', 'assertion_replayed',
        'invalid_assertion', 'assertion_outside_conditions',
        'no_email', 'jit_disabled', 'membership_suspended', 'seat_quota_exhausted',
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
            ->where('reason', SsoFailureReason::ExistingAccountNotMember->value)
            ->delete();

        $this->recreate(self::PRE_M1_REASONS);
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
