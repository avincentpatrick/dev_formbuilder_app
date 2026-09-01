{{--
    Override of the framework's mail header (H23a4). The ONLY file this application publishes under
    `resources/views/vendor/mail/` — every other component falls through to `vendor/laravel/framework`,
    because `Markdown::componentPaths()` searches the published directory first and the framework's second.

    ── WHY THE LOGO CANNOT SIMPLY BE WRITTEN INTO THE HEADER SLOT ──────────────────────────────────
    The same view renders through TWO arms: `MailChannel::buildView()` returns an html closure and a text
    closure over one view name, and `Markdown::renderText()` re-points the `mail` namespace at `text/`.
      - An `<img>` written into the layout's header slot is `strip_tags()`ed by `text/layout.blade.php`,
        so logo-bearing tenants would get a blank line at the top of every plaintext email.
      - An `<img>` passed through the STOCK header's slot is escaped by `text/header.blade.php`'s
        text-context echo, so the plaintext body would print a literal `<img src=…>`.
    Overriding the component is the framework's own answer: the html arm renders this file, the text arm
    renders `text/header.blade.php` — which we deliberately do NOT publish. `AnonymousComponent::data()`
    merges the attribute bag into scope, so the extra `logo` attribute is simply unused over there.

    The framework's copy branches on `trim($slot) === 'Laravel'` to swap in its own hosted logo. That
    special case is gone; the branch is on whether the tenant HAS a logo.

    ── EVERY ATTRIBUTE HERE GOES THROUGH `MailAttribute::escape()`, AND A BLADE ECHO WOULD NOT DO ──
    ⛔ **THIS FILE PREVIOUSLY CARRIED THE OPPOSITE CLAIM AND IT WAS FALSE (M57).** It said the `alt` was
    safe because the slot *"arrived already escaped with ENT_QUOTES"*. It does not: `AppServiceProvider`
    enables `Markdown::withSecuredEncoding()`, which swaps the echo encoder for a three-character map —
    `[`, `<`, `>` — for the whole render. **No `"`.** A tenant named `Acme" onerror="alert(1)` therefore
    closed the `alt` and appended a live event handler to this `<img>`; measured on the rendered output,
    where it survives the `CssToInlineStyles` DOM round-trip because the break-out happens before any
    parser sees the markup.

    So a Blade echo is NOT the fix on this surface — `{{ }}` runs that same three-character map.
    {@see \App\Support\Mail\MailAttribute} carries the mechanism and the reason its `double_encode: false`
    is load-bearing. `scripts/mail-attribute-lint.php` keeps this true for the next mail view.
--}}
@props(['url', 'logo' => ''])
<tr>
<td class="header">
<a href="{!! \App\Support\Mail\MailAttribute::escape($url) !!}" style="display: inline-block;">
@if ($logo !== '')
<img src="{!! \App\Support\Mail\MailAttribute::escape($logo) !!}" class="logo" alt="{!! \App\Support\Mail\MailAttribute::escape(trim($slot)) !!}">
@else
{{--
    TEXT context, not attribute context — and it must stay raw. The secured map has already escaped `<`
    and `>`, so no tag can be opened here; escaping again would double-encode and ship `&amp;lt;` to the
    reader. The M57 backlog row counted this as a second defective sink and it is not one: the hazard is
    the CONTEXT, never the directive.
--}}
{!! $slot !!}
@endif
</a>
</td>
</tr>
