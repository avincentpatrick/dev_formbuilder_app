<?php

declare(strict_types=1);

namespace App\Services\Sso;

use App\Support\Audit\AuditableTypes;
use Illuminate\Support\Carbon;

/**
 * Reads what a stored IdP signing certificate SAYS about itself, for the settings screen (P1a — ADR-0016 §D11).
 *
 * A pure, stateless lookup: no database, no framework state beyond the clock, exhaustively unit-testable —
 * the {@see AuditableTypes} posture. Separate from the presenter rather than private to it so that a second
 * implementation of "is this key inside its validity window right now?" never has to be written.
 *
 * ── ✅ CLOSED IN M2: THE LOGIN PATH NOW ASKS THIS QUESTION, AND THE ANSWER CAN REFUSE A SIGN-IN ──────
 * This class had exactly one consumer — {@see SsoConnectionPresenter} — while php-saml verified a
 * signature against the stored certificate WITHOUT parsing its validity window, so an expired IdP signing
 * certificate authenticated assertions indefinitely while `/settings/sso` rendered it as expired. That was
 * a Residual in `docs/security-threat-model.md` §8 and §9 item 18, and M2 is the increment it was owed.
 *
 * The second consumer is {@see SsoLoginService::consumeAssertion()}, which calls {@see signingState()}
 * ahead of the whole sequence and refuses with `SsoFailureReason::IdpCertificateUnusable` when the answer
 * is outside {@see USABLE_STATES}. It is the §D11 roll-up rule applied on the login path: refuse only when
 * NO certificate in the set is currently usable, so an admin's unfinished rollover is never a total outage.
 *
 * ⚠️ AND THE RESIDUAL THIS NARROWS RATHER THAN ERASES, STATED HERE SO NOBODY READS IT AS ABSENT: while at
 * least one stored certificate is valid, an EXPIRED sibling in the same set can still verify a signature —
 * php-saml is handed the whole set, and §D31 records why filtering it was rejected (a not-yet-valid
 * successor is minutes away by design during a rollover, so filtering on our clock would make skew into an
 * availability control). §D10's atomic whole-half import is what keeps that set from accumulating dead keys.
 *
 * A false claim about a control is worse than a missing one: it stops the next reader looking.
 *
 * ── WHY THE PARSER DOES NOT DO THIS AT IMPORT ────────────────────────────────────────────────────────
 * {@see SsoMetadataParser::assertParsable()} deliberately checks parsability and NOT validity dates: an IdP
 * legitimately publishes a not-yet-active successor alongside its current key during a rollover, and
 * refusing the document would make a correctly-executed rotation fail at the SP. Expiry is a thing to
 * SHOW a human who can act on it, not a thing to refuse. This class is the other half of that decision.
 *
 * ⚠️ NOTHING HERE RETURNS THE CERTIFICATE BODY. Every field is a fact ABOUT the key.
 */
final class SsoCertificateInspector
{
    /** How far ahead of `notAfter` the screen starts asking for a re-import. */
    public const int EXPIRY_WARNING_DAYS = 30;

    /**
     * The {@see rollup()} states meaning "at least one stored key can verify a signature right now".
     *
     * ⚠️ PUBLIC BECAUSE THE LOGIN PATH DECIDES WITH IT (M2), and a second lane of code deciding what
     * "usable" means is exactly how the settings screen and the ACS came to disagree in the first place.
     * `expiring_soon` is usable and that is the point: it is a WARNING, not a refusal.
     *
     * @var list<string>
     */
    public const array USABLE_STATES = ['ok', 'expiring_soon'];

    /**
     * The PER-ROW states meaning that one certificate can verify a signature right now.
     *
     * Deliberately a different vocabulary from {@see USABLE_STATES}: a row is `valid`, a whole connection
     * is `ok`. Naming them the same would invite a comparison across the two, which is a set membership
     * test against the wrong set.
     *
     * @var list<string>
     */
    private const array LIVE_ROW_STATES = ['valid', 'expiring_soon'];

    /**
     * One row per stored certificate, in stored order.
     *
     * @param  list<string>  $certificates  bare base64 DER, exactly as `sso_connections.idp_certificates` holds it
     * @return list<array{
     *     subject: ?string,
     *     issuer: ?string,
     *     serial: ?string,
     *     thumbprint_short: ?string,
     *     not_before: ?string,
     *     not_after: ?string,
     *     expires_in_days: ?int,
     *     state: string
     * }>
     */
    public function inspect(array $certificates, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        return array_map(
            fn (string $certificate): array => $this->row($certificate, $now),
            $certificates,
        );
    }

    /**
     * The one word the page leads with, across the whole certificate set.
     *
     * ⚠️ THREE OF THESE WARNINGS NOW OPEN WITH "Sign-in is refused" (M2), AND THAT IS A CORRECTION RATHER
     * THAN A TONE CHANGE. Until M2 the expired warning read as an errand — re-import to pick up the
     * replacement — because sign-in genuinely kept working. It no longer does, and a warning that
     * understates an outage sends an admin to the bottom of their queue with members locked out.
     * `expiring_soon` is untouched: that tenant can still sign in, and telling them otherwise would be the
     * same error in the other direction.
     *
     * ⚠️ THIS IS NOT "WORST STATE WINS", AND THAT IS THE DECISION. A rollover pair — one live key plus a
     * successor whose `notBefore` is in the future — is an IdP doing exactly the right thing, and flagging
     * it red is how an indicator becomes noise an admin learns to scroll past. So: if ANY certificate is
     * usable right now, the connection is fine.
     *
     * @param  list<array{state: string, expires_in_days: ?int, not_after: ?string}>  $rows
     * @return array{state: string, warning: ?string}
     */
    public function rollup(array $rows): array
    {
        if ($rows === []) {
            return ['state' => 'unreadable', 'warning' => 'No signing certificate is stored. Re-import your identity provider’s metadata.'];
        }

        $states = array_column($rows, 'state');

        // Any currently-usable key ⇒ the connection works. The only question left is how soon it stops.
        $live = array_values(array_filter(
            $rows,
            static fn (array $row): bool => in_array($row['state'], self::LIVE_ROW_STATES, true),
        ));

        if ($live !== []) {
            $soonest = $this->soonest($live);

            if ($soonest === null || $soonest['state'] !== 'expiring_soon') {
                return ['state' => 'ok', 'warning' => null];
            }

            return [
                'state' => 'expiring_soon',
                'warning' => sprintf(
                    'The signing certificate expires in %d %s. Re-import your identity provider’s metadata to pick up the replacement before it does.',
                    max(0, (int) $soonest['expires_in_days']),
                    (int) $soonest['expires_in_days'] === 1 ? 'day' : 'days',
                ),
            ];
        }

        if (in_array('expired', $states, true)) {
            $latest = $this->latestExpiry($rows);

            return [
                'state' => 'expired',
                'warning' => $latest === null
                    ? 'Sign-in is refused: the signing certificate has expired. Re-import your identity provider’s metadata to pick up the replacement.'
                    : sprintf(
                        'Sign-in is refused: the signing certificate expired on %s. Your identity provider has almost certainly published a replacement — re-import its metadata to pick it up.',
                        Carbon::parse($latest)->format('j F Y'),
                    ),
            ];
        }

        if (in_array('not_yet_valid', $states, true)) {
            return [
                'state' => 'not_yet_valid',
                'warning' => 'Sign-in is refused: the stored signing certificate is not valid yet. It will keep being refused until that certificate becomes active, or until you import the one your provider is currently using.',
            ];
        }

        return [
            'state' => 'unreadable',
            'warning' => 'Sign-in is refused: we can no longer read the stored signing certificate. Re-import your identity provider’s metadata.',
        ];
    }

    /**
     * The one word the LOGIN path decides on — {@see rollup()} run over a raw certificate set (M2).
     *
     * The whole of this class's second consumer. Kept here rather than composed at the call site so that
     * "can this connection still authenticate anybody?" has exactly one implementation, which is the same
     * reason {@see inspect()} was never private to the presenter.
     *
     * ⚠️ AN EMPTY SET CANNOT REACH THIS FROM THE ACS, and no guard is owed for it here.
     * `SsoConnection::servesProtocol()` is `status->servesProtocol() && $this->idp_certificates !== []`, so
     * `SsoGate::activeConnectionOrAbort()` has already answered 404 before the login service runs. The
     * `$rows === []` arm of {@see rollup()} is a settings-screen concern, and it stays one.
     *
     * @param  list<string>  $certificates  bare base64 DER, exactly as `sso_connections.idp_certificates` holds it
     */
    public function signingState(array $certificates, ?Carbon $now = null): string
    {
        return $this->rollup($this->inspect($certificates, $now))['state'];
    }

    /**
     * @return array{
     *     subject: ?string, issuer: ?string, serial: ?string, thumbprint_short: ?string,
     *     not_before: ?string, not_after: ?string, expires_in_days: ?int, state: string
     * }
     */
    private function row(string $certificate, Carbon $now): array
    {
        $pem = SsoMetadataParser::pem($certificate);

        // ⚠️ NEVER THROWS. openssl_x509_read() succeeded at import, but an OpenSSL major-version upgrade can
        // start refusing a key it once read (legacy algorithms, short moduli) — and a settings page that 500s
        // on a stored value strands the tenant with no route to fix the very thing that broke.
        $parsed = @openssl_x509_parse($pem);

        if ($parsed === false) {
            return [
                'subject' => null, 'issuer' => null, 'serial' => null, 'thumbprint_short' => null,
                'not_before' => null, 'not_after' => null, 'expires_in_days' => null,
                'state' => 'unreadable',
            ];
        }

        $notBefore = $this->timestamp($parsed, 'validFrom_time_t');
        $notAfter = $this->timestamp($parsed, 'validTo_time_t');

        return [
            'subject' => $this->commonName($parsed, 'subject'),
            'issuer' => $this->commonName($parsed, 'issuer'),
            'serial' => $this->text($parsed, 'serialNumberHex'),
            'thumbprint_short' => $this->thumbprint($pem),
            'not_before' => $notBefore?->toIso8601String(),
            'not_after' => $notAfter?->toIso8601String(),
            // Signed on purpose: negative reads as "expired N days ago" without a second field.
            'expires_in_days' => $notAfter === null ? null : (int) floor($now->diffInDays($notAfter, false)),
            'state' => $this->state($now, $notBefore, $notAfter),
        ];
    }

    private function state(Carbon $now, ?Carbon $notBefore, ?Carbon $notAfter): string
    {
        if ($notBefore !== null && $now->lessThan($notBefore)) {
            return 'not_yet_valid';
        }

        if ($notAfter === null) {
            return 'unreadable';
        }

        if ($now->greaterThanOrEqualTo($notAfter)) {
            return 'expired';
        }

        return $now->diffInDays($notAfter, false) <= self::EXPIRY_WARNING_DAYS
            ? 'expiring_soon'
            : 'valid';
    }

    /**
     * The SHA-256 thumbprint, first 12 hex characters.
     *
     * This is the PER-CERTIFICATE value an IdP console displays as "Thumbprint" — deliberately not the same
     * thing as `sso_connections.idp_certificates_fingerprint`, which hashes the whole SET and therefore
     * matches nothing an admin can see on the other side of the trust relationship.
     */
    private function thumbprint(string $pem): ?string
    {
        $fingerprint = @openssl_x509_fingerprint($pem, 'sha256');

        return $fingerprint === false ? null : mb_substr($fingerprint, 0, 12);
    }

    /** @param array<string, mixed> $parsed */
    private function commonName(array $parsed, string $key): ?string
    {
        $section = $parsed[$key] ?? null;

        if (! is_array($section)) {
            return null;
        }

        // A multi-valued RDN gives an array here rather than a string. Narrow rather than stringify: a
        // "CN: Array" on a settings page is worse than an absent line.
        $common = $section['CN'] ?? null;

        return is_string($common) && $common !== '' ? $common : null;
    }

    /** @param array<string, mixed> $parsed */
    private function text(array $parsed, string $key): ?string
    {
        $value = $parsed[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $parsed */
    private function timestamp(array $parsed, string $key): ?Carbon
    {
        $value = $parsed[$key] ?? null;

        return is_int($value) ? Carbon::createFromTimestampUTC($value) : null;
    }

    /**
     * @param  list<array{state: string, expires_in_days: ?int, not_after: ?string}>  $rows
     * @return array{state: string, expires_in_days: ?int, not_after: ?string}|null
     */
    private function soonest(array $rows): ?array
    {
        $best = null;

        foreach ($rows as $row) {
            if ($row['expires_in_days'] === null) {
                continue;
            }

            if ($best === null || $row['expires_in_days'] > (int) $best['expires_in_days']) {
                $best = $row;
            }
        }

        // The LONGEST-lived usable key decides, not the shortest: while one key still has months on it the
        // tenant has nothing to do, and warning about its retiring sibling is the rollover noise §D11 refuses.
        return $best;
    }

    /** @param list<array{state: string, expires_in_days: ?int, not_after: ?string}> $rows */
    private function latestExpiry(array $rows): ?string
    {
        $dates = array_values(array_filter(
            array_column($rows, 'not_after'),
            static fn (?string $value): bool => is_string($value) && $value !== '',
        ));

        if ($dates === []) {
            return null;
        }

        sort($dates);

        return (string) end($dates);
    }
}
