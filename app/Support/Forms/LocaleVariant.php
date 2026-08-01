<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Services\Submissions\SchemaValueFormatter;
use App\Services\Submissions\SubmissionPdfPresenter;

/**
 * The ONE rule for picking a `*_translations` variant out of a translations map (Doc #26 §4,
 * amendment A8): a missing, non-string or BLANK variant is no variant at all, and the caller
 * falls back to the base column.
 *
 * Extracted in H17 because it acquired its second PHP caller. It shipped in H6b as a private
 * method on {@see SchemaValueFormatter} (option labels), and H17's
 * {@see SubmissionPdfPresenter} needs the identical rule for field and section labels — the half
 * of §4's normative order the inbox never implemented. Two private copies of a four-line rule is
 * exactly the drift shape this codebase hunts, so there is one copy and both callers use it.
 *
 * NOTE the deliberate asymmetry it encodes, which is NOT an oversight: the locale VARIANT falls
 * back on blank, but the BASE label falls back on null only. An author who writes `"label": ""`
 * meant it; an author who leaves a translation empty has simply not translated it yet. The
 * fallback chain therefore reads: variant (non-blank) → base (non-null) → the field's key.
 *
 * The TypeScript twin is `resolveText()` in the guest runtime. This class is not vectored in the
 * golden template corpus because it operates on the label BEFORE a template is parsed — §4's
 * "resolve the locale, THEN render" order means variant selection happens strictly upstream of
 * anything the corpus pins.
 */
final class LocaleVariant
{
    /**
     * The `$locale` variant from a translations map, or null when there is none to use.
     *
     * @param  mixed  $translations  the raw `*_translations` value; anything but an array is no map at all
     */
    public static function from(mixed $translations, ?string $locale): ?string
    {
        if ($locale === null || ! is_array($translations)) {
            return null;
        }

        $value = $translations[$locale] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The full fallback chain: the locale variant, else the base text, else `$fallback`.
     *
     * @param  mixed  $translations  the raw `*_translations` value
     */
    public static function resolve(mixed $translations, ?string $base, ?string $locale, string $fallback = ''): string
    {
        return self::from($translations, $locale) ?? $base ?? $fallback;
    }
}
