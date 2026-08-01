<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Submissions\StructuralAnswerNormalizer;

/**
 * Where a `hidden` field's value comes from (Increment H7). A hidden field is the one field type a
 * respondent can neither see nor edit, so *something else* has to supply its value — and which something
 * decides whether the submitted value may be trusted at all.
 *
 * ── The rule this enum exists to make decidable ─────────────────────────────────────────────────────
 * A `Fixed` field is **server-authoritative**: {@see StructuralAnswerNormalizer} writes the authored
 * literal over whatever arrived, so no client can influence it. A `Url` field is genuinely untrusted —
 * the value crosses the boundary from outside the form — so it is accepted, but only as a scalar under
 * {@see MAX_VALUE_BYTES}. `None` holds nothing and is dropped like a `calculated` field.
 *
 * That split is what the H-map's "server-constrained untrusted input" means concretely, and it is the
 * property H20 was told to rely on: an amount must be recomputed server-side from trusted inputs and
 * never bound to a client-prefillable field. With this enum, "is this field client-prefillable?" is one
 * call rather than a judgement.
 *
 * ── Opt-in, fail-closed ─────────────────────────────────────────────────────────────────────────────
 * A hidden field is URL-fillable ONLY when the author declares `config.prefill_source = 'url'`. The field
 * key is deliberately NOT an implicit parameter name: an implicit mapping would make every hidden field
 * client-writable by default, which is a denylist wearing an allowlist's clothes — exactly the shape H8
 * was written to remove from `ocr_compatible`.
 *
 * ── Why this is NOT a `default`-less match over all 31 FieldType cases ──────────────────────────────
 * {@see PipingEligibility} and {@see OcrFieldEligibility} classify the *type catalog*, so a 32nd field
 * type must be classified before it compiles. This classifies a hidden field's *config*, and the type
 * dimension is the single question "is it `hidden`?" — a new field type is `None` and that is correct
 * without anyone deciding it. Claiming the forcing device here would be cargo-culting it.
 */
enum PrefillSource: string
{
    /** The authored `form_fields.default_value` literal. Server-authoritative — a client value is dropped. */
    case Fixed = 'fixed';

    /**
     * Supplied from outside the form. On the guest/PWA channel that is a URL query parameter; on the
     * manual-encode channel it is what the keyer typed; on an API import it is what the caller posted.
     * Untrusted on every one of them, and constrained identically on every one of them — a channel-aware
     * carve-out would leave the same form yielding different data per channel.
     */
    case Url = 'url';

    /** No source declared. The field holds nothing; a submitted value is dropped. */
    case None = 'none';

    /**
     * The hard cap on a {@see Url} value, in bytes. Matches `default_value`'s `max:2000` FormRequest rule
     * and the template byte cap, so the three author-facing text limits agree.
     *
     * Over-cap is a Stage-1 STRUCTURAL fault (422), not a truncation: silently shortening an answer
     * corrupts the data for every downstream reader, and a 2000-byte query parameter is not a typo.
     */
    public const MAX_VALUE_BYTES = 2000;

    /**
     * The declared source for one field. Total: every non-`hidden` type, and every hidden field whose
     * config declares nothing recognisable, is {@see None}.
     *
     * @param  array<string, mixed>|null  $config  `form_fields.config`, live row or frozen snapshot alike
     */
    public static function for(FieldType $type, ?array $config): self
    {
        if ($type !== FieldType::Hidden) {
            return self::None;
        }

        $declared = $config['prefill_source'] ?? null;

        if (! is_string($declared)) {
            return self::None;
        }

        return self::tryFrom($declared) ?? self::None;
    }

    /**
     * The query-parameter name a {@see Url} field reads. Blank/absent falls back to the field's own key,
     * which is the ergonomic default — the author already had to opt in via `prefill_source`, so the
     * fallback widens nothing.
     *
     * @param  array<string, mixed>|null  $config
     */
    public static function urlParam(?array $config, string $fieldKey): string
    {
        $name = $config['url_param'] ?? null;

        return is_string($name) && trim($name) !== '' ? trim($name) : $fieldKey;
    }
}
