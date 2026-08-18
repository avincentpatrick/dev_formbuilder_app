<?php

declare(strict_types=1);

namespace App\Support\Audit;

/**
 * Redacts an audit row's `old_values`/`new_values` before they are written (audit-compliance-logging-spec
 * §2). A pure, stateless transform — no database, no framework — so it is exhaustively unit-testable.
 *
 * Principle (§2): the ledger records THAT something changed and roughly what, never a field's raw sensitive
 * content. Every redacted key is replaced with {@see self::PLACEHOLDER} on BOTH sides and its name recorded
 * in `redacted_fields` — a transparency record of the redaction that does not defeat itself by naming the
 * value. Redacting both sides uniformly is also what makes the §2.1 erasure special case correct BY
 * CONSTRUCTION: an erasure's `old_values` never carries the pre-erasure raw value, because a sensitive/erased
 * key is placeholdered on the "before" side too — not the naive "old = real, new = redacted" a normal diff
 * would produce.
 *
 * The redacted key set for a call is: the unconditional secret columns for the auditable type, plus the
 * PII columns for that type, plus any keys the caller flags for this specific operation (`$erasedKeys` — a
 * GDPR erasure; `$piiAnswerKeys` — answer keys whose form field is is_pii/is_sensitive, resolved by the
 * caller since that lookup needs the form schema).
 */
final class AuditRedactor
{
    public const string PLACEHOLDER = '[REDACTED]';

    /**
     * Always redacted, unconditionally (§2): platform-level secrets/credentials, keyed by the spec §1
     * auditable-type alias. Not tenant-configurable.
     *
     * @var array<string, list<string>>
     */
    private const array SECRETS = [
        'users' => ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'],
        'webhook_endpoint' => ['secret', 'secret_previous'],
        // A native-connector OAuth grant (H15a / ADR-0009 §D10). Registered in the SAME increment that
        // creates the table: an unregistered credential-bearing alias is silently un-redacted and nothing
        // detects the omission. Highest blast radius of anything on this list — these tokens act inside the
        // tenant's own third-party workspace, not against this platform.
        'connection' => ['access_token', 'refresh_token'],
        // The tenant's IdP signing certificates (P1a / ADR-0016 §D12) — and the ONE entry here that is
        // not a secret. A signing certificate is public by construction; `encrypted:array` on that column
        // is an INTEGRITY claim, because anything that can silently rewrite it makes the application trust
        // assertions minted by a key of the attacker's choosing.
        //
        // It is registered anyway, and for a mechanical reason rather than a confidentiality one:
        // `Model::getOriginal()` maps every attribute through `transformModelValue()`, so it returns this
        // column DECRYPTED — while `$hidden` guards only `toArray()`/`toJson()`. The repo's ordinary
        // snapshot idiom (`Arr::only($model->getOriginal(), …)`, TenantSettingsService:102) would therefore
        // write plaintext keys into a table that is append-only by RLS policy and never pruned.
        // SsoConnectionService builds its payload by hand and the column is structurally absent from it;
        // this line is what makes a later "simplification" produce [REDACTED] instead of a wall of base64.
        'sso_connection' => ['idp_certificates'],
        'tenant_users' => ['invite_token'],
        'personal_access_tokens' => ['token'],
    ];

    /**
     * Redacted because the Data Dictionary marks the column PII (§2), keyed by auditable-type alias.
     *
     * @var array<string, list<string>>
     */
    private const array PII = [
        // `remarks` joined this list in I2, when SubmissionReviewService started auditing the four review
        // transitions. It is registered for the SAME reason `feedback_reports.remarks` already is: free
        // text with no audience discipline, where a reviewer can paste respondent PII copied straight out
        // of the answers ("called the mother, number is …"). `audits` is append-only and never deleted,
        // and data-privacy-gdpr-compliance §9's whole reconciliation rests on this table never having
        // stored raw PII — so the ledger records THAT the remarks changed (via `redacted_fields`) without
        // recording what they said, which is §2's principle exactly.
        //
        // `returned_reason` is deliberately NOT here, and the asymmetry is the point: it is the reviewer's
        // explanation AUTHORED FOR the respondent and already emailed to them, so "why was this returned"
        // is precisely the accountability the ledger exists to preserve.
        'submission' => ['guest_ip', 'guest_user_agent', 'guest_contact_email', 'remarks'],
        'attachment' => ['original_filename'],
        'feedback_reports' => ['remarks', 'browser_info'],
    ];

    /**
     * @param  ?array<string, mixed>  $old
     * @param  ?array<string, mixed>  $new
     * @param  list<string>  $erasedKeys  keys erased by a GDPR erasure (both sides placeholdered — §2.1)
     * @param  list<string>  $piiAnswerKeys  answer keys whose field is is_pii/is_sensitive
     * @return array{old: ?array<string, mixed>, new: ?array<string, mixed>, redacted_fields: ?list<string>}
     */
    public function redact(
        string $auditableType,
        ?array $old,
        ?array $new,
        array $erasedKeys = [],
        array $piiAnswerKeys = [],
    ): array {
        $sensitive = array_values(array_unique([
            ...(self::SECRETS[$auditableType] ?? []),
            ...(self::PII[$auditableType] ?? []),
            ...$erasedKeys,
            ...$piiAnswerKeys,
        ]));

        $redacted = [];

        $old = $this->apply($old, $sensitive, $redacted);
        $new = $this->apply($new, $sensitive, $redacted);

        sort($redacted);

        return [
            'old' => $old,
            'new' => $new,
            'redacted_fields' => $redacted === [] ? null : array_values(array_unique($redacted)),
        ];
    }

    /**
     * Replace each sensitive key present in $values with the placeholder, recording which were actually
     * stripped. Returns the array unchanged (a copy) when nothing matches; null passes through.
     *
     * @param  ?array<string, mixed>  $values
     * @param  list<string>  $sensitive
     * @param  list<string>  $redacted  accumulator (by-ref) of stripped key names
     * @return ?array<string, mixed>
     */
    private function apply(?array $values, array $sensitive, array &$redacted): ?array
    {
        if ($values === null) {
            return null;
        }

        foreach ($sensitive as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = self::PLACEHOLDER;
                $redacted[] = $key;
            }
        }

        return $values;
    }
}
