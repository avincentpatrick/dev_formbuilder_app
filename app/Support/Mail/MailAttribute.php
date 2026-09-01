<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Providers\AppServiceProvider;
use Illuminate\Mail\Markdown;
use Illuminate\Support\EncodedHtmlString;

/**
 * Escapes a value for an HTML **attribute** inside a markdown-mail view (M57).
 *
 * ── WHY THIS EXISTS, AND WHY `{{ }}` IS NOT THE ANSWER ────────────────────────────────────────────────
 * {@see AppServiceProvider::boot()} calls {@see Markdown::withSecuredEncoding()} — H23a4, closing a real
 * markdown-injection defect. That call has a consequence nothing in this repository stated until M57:
 * {@see Markdown::render()} replaces {@see EncodedHtmlString}'s encoder for the whole render with a
 * **three-character map** — `[` to a backslash-escaped `[`, `<` to `&lt;`, `>` to `&gt;`. **No `"`. No
 * `'`. No `&`.**
 *
 * Read the vendor source for the version installed rather than trusting this paragraph; the map is a
 * literal array in `Markdown::render()`.
 *
 * So on this surface `{{ }}` does NOT run `htmlspecialchars`, and a Blade echo of any kind inside a
 * quoted attribute is quote-unsafe. Off this surface — every other Blade view in the application — `{{ }}`
 * IS `htmlspecialchars(ENT_QUOTES)` and this class must not be used; it would be a second escape.
 *
 * ── `double_encode: false` IS LOAD-BEARING, NOT AN INHERITED DEFAULT ──────────────────────────────────
 * The value reaching this method has ALREADY been through the secured map, so it may hold `&lt;`. At the
 * default `double_encode: true` that becomes `&amp;lt;`, and the audience that reads it is precisely the
 * images-off audience an `alt` exists for. That is the same failure the header's original comment was
 * written to avoid — it simply named the wrong mechanism.
 *
 * ⚠️ **`&` IS ESCAPED HERE AND WOULD ALSO BE ESCAPED WITHOUT US.** `CssToInlineStyles` re-parses and
 * re-serialises the whole document, and `DOMDocument` emits a bare `&` in an attribute value as `&amp;`.
 * Measured, not assumed. The `&` half of this call is therefore belt-and-braces; **the `"` half is the
 * one that matters**, because a quote breaks the attribute at string-concatenation time, before any
 * parser sees it — which is why the injected attribute survives the inliner instead of being repaired
 * by it.
 */
final class MailAttribute
{
    /**
     * Escape a markdown-mail value for interpolation inside a quoted HTML attribute.
     */
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', double_encode: false);
    }
}
