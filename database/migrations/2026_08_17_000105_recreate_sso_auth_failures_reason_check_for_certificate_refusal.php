<?php

declare(strict_types=1);

use App\Enums\SsoFailureReason;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M2 — widen `sso_auth_failures.reason` for the trust-anchor refusal (ADR-0016 §D31).
 *
 * `SsoFailureReason::IdpCertificateUnusable` is recorded when no stored IdP signing certificate is inside
 * its validity window, so `SsoLoginService::consumeAssertion()` refuses before reading the document at all.
 * Until M2 that state produced a SESSION rather than a refusal — see `SsoCertificateInspector`'s docblock
 * for the takeover chain it closes, and `docs/security-threat-model.md` §9 item 18 for the residual it
 * discharges.
 *
 * ⚠️ THE CHECK IS WHY THIS FILE EXISTS AT ALL, AND IT IS THE THIRD INSTANCE OF ONE SHAPE — M1's
 * `2026_08_17_000104` and K1b's `2026_08_17_000103` before it. `2026_08_15_000002` CHECK-constrains
 * `reason` to `SsoFailureReason::values()` so the failures panel's vocabulary cannot drift from the enum.
 * Adding a case without widening the constraint would turn the new refusal into a 23514 at the moment it
 * fires — the guard throwing WHILE BEING RECORDED, on the one endpoint anyone on the internet can post to,
 * which converts a clean 404 into a 500 and is itself the §D4 disclosure the uniform response exists to
 * prevent.
 *
 * `up()` regenerates from the CURRENT enum rather than a frozen list, so a later case cannot be silently
 * dropped by a chained recreation; only `down()` names the frozen pre-M2 vocabulary, because that is the
 * one list this file is the authority on.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'sso_auth_failures_reason_check';

    /** The 17 reasons that existed before M2 — M1's 16 plus its own. Named only here, and only for the reversal. */
    private const PRE_M2_REASONS = [
        'response_missing', 'response_too_large', 'response_malformed',
        'unsolicited_assertion', 'unknown_request', 'request_replayed', 'assertion_replayed',
        'invalid_assertion', 'assertion_outside_conditions',
        'no_email', 'jit_disabled', 'existing_account_not_member', 'membership_suspended', 'seat_quota_exhausted',
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
            ->where('reason', SsoFailureReason::IdpCertificateUnusable->value)
            ->delete();

        $this->recreate(self::PRE_M2_REASONS);
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
