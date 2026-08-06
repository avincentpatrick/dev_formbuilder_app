<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Services\Audit\AuditExporter;
use App\Services\Audit\AuditLogPresenter;
use App\Services\Submissions\SubmissionInboxPresenter;
use App\Services\Tenancy\CustomDomainService;

/**
 * Renders an audit row's `old_values`/`new_values` jsonb pair into display-ready before/after rows (I2).
 * A pure, stateless transform — no database, no framework — for the same reason {@see AuditRedactor} is
 * one: it is exhaustively unit-testable, and it has TWO consumers ({@see AuditLogPresenter}
 * and {@see AuditExporter}) that must not be able to disagree about what a diff says.
 *
 * Every value is stringified HERE, server-side, and the client only lays the result out — the same
 * division {@see SubmissionInboxPresenter} uses for answer values. Shipping raw
 * jsonb to Vue would push type-formatting decisions into a template where they cannot be tested.
 *
 * ⚠️ **THE REDACTED CASE IS NOT A SPECIAL CASE, AND MUST NOT BE MADE ONE.** By the time a row is written,
 * {@see AuditRedactor} has ALREADY replaced a sensitive key with the placeholder on BOTH sides — so an
 * erasure diff legitimately reads `[REDACTED] → [REDACTED]`, and there is nothing else to show, because
 * the real prior value was never stored (audit-compliance-logging-spec §2.1, which exists precisely
 * because a future implementer will try to "fix" the before side back to a literal value). This class
 * renders what is stored and flags the key; it never reconstructs, nulls or hides it.
 */
final class AuditDiff
{
    /**
     * Screen preview cap. The exporter passes `null` — a spreadsheet cell is where you go to read the
     * whole value, so truncating there would defeat the reason someone exported.
     */
    public const int PREVIEW_CHARS = 300;

    /** Rendered in place of a key that is present but holds `null` (a real column set to nothing). */
    private const string NULL_VALUE = '—';

    private const string TRUNCATION_SUFFIX = '…';

    /**
     * One row per key PRESENT in either payload, in a stable, author-meaningful order.
     *
     * ⚠️ **PRESENT, not CHANGED — there is deliberately no equality test, and adding one would lose
     * information.** A caller records the fields that describe the act, not a computed delta: a domain
     * audit carries the hostname on both sides precisely so the row reads without a join
     * ({@see CustomDomainService}, where the target cannot be addressed by key at
     * all), and a review transition snapshots the whole reviewable state so one emission site serves four
     * verbs. Filtering out equal pairs would strip exactly those anchors and leave rows that no longer say
     * what they are about. The ledger records what the payload said; deciding what is interesting is the
     * reader's job, not this transform's.
     *
     * `null` on a side means **the key was not present in that payload at all** — deliberately distinct
     * from a present-but-null column, which renders as an em-dash. `public_slug: — → intake` (the column
     * was empty and now has a value) and `public_slug: (absent) → intake` (a `created` event that never
     * had a before) are different facts and the ledger says so.
     *
     * @param  ?array<string, mixed>  $old
     * @param  ?array<string, mixed>  $new
     * @param  ?list<string>  $redacted  `audits.redacted_fields` — the names AuditRedactor stripped
     * @param  ?int  $cap  max characters per rendered value; null for uncapped (export)
     * @return list<array{key: string, old: ?string, new: ?string, redacted: bool}>
     */
    public static function rows(?array $old, ?array $new, ?array $redacted, ?int $cap = self::PREVIEW_CHARS): array
    {
        $old ??= [];
        $new ??= [];
        $redactedKeys = $redacted ?? [];

        $rows = [];

        foreach (self::keys($old, $new) as $key) {
            $rows[] = [
                'key' => $key,
                'old' => array_key_exists($key, $old) ? self::render($old[$key], $cap) : null,
                'new' => array_key_exists($key, $new) ? self::render($new[$key], $cap) : null,
                'redacted' => in_array($key, $redactedKeys, true),
            ];
        }

        return $rows;
    }

    /**
     * The key union: `new`'s keys in insertion order, then any `old`-only keys appended.
     *
     * NOT sorted, and that is load-bearing. These payloads are literal arrays a service author wrote in
     * a deliberate order (`['public_slug' => …, 'allow_guest_submissions' => …]`); `ksort` would scramble
     * a readable pair into alphabetical nonsense and would put `archived_at` above `status` on the one
     * row where `status` is the whole story.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return list<string>
     */
    private static function keys(array $old, array $new): array
    {
        $keys = array_keys($new);

        foreach (array_keys($old) as $key) {
            if (! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        // Cast: array_keys() over a jsonb-decoded map can hand back int-like keys as ints.
        return array_map(strval(...), $keys);
    }

    /** Stringify one jsonb value for display. `null` here is a stored null, not an absent key. */
    private static function render(mixed $value, ?int $cap): string
    {
        $rendered = match (true) {
            $value === null => self::NULL_VALUE,
            // Before is_scalar: a bool is scalar, and `1`/`''` is unreadable in a ledger. The first
            // consumer is `allow_guest_submissions`.
            is_bool($value) => $value ? 'Yes' : 'No',
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            // JSON_THROW_ON_ERROR is not decoration: without it json_encode() is `string|false` and
            // PHPStan level 8 rejects the concatenation. Values here came out of jsonb, so a throw is
            // unreachable in practice.
            default => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };

        return self::cap($rendered, $cap);
    }

    private static function cap(string $value, ?int $cap): string
    {
        if ($cap === null || mb_strlen($value) <= $cap) {
            return $value;
        }

        return mb_substr($value, 0, $cap).self::TRUNCATION_SUFFIX;
    }
}
