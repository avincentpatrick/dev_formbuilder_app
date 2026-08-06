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
        `{{ $slot }}`, so the plaintext body would print a literal `<img src=…>`.
    Overriding the component is the framework's own answer: the html arm renders this file, the text arm
    renders `text/header.blade.php` — which we deliberately do NOT publish. `AnonymousComponent::data()`
    merges the attribute bag into scope, so the extra `logo` attribute is simply unused over there.

    The framework's copy branches on `trim($slot) === 'Laravel'` to swap in its own hosted logo. That
    special case is gone; the branch is on whether the tenant HAS a logo.
--}}
@props(['url', 'logo' => ''])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if ($logo !== '')
{{--
    `alt` uses {!! !!} on purpose, and it is safe: the slot arrived from `mail.notification` already
    escaped with ENT_QUOTES ({{ $brand['name'] }}), so escaping it a second time would ship
    `Acme &amp;amp; Co` to every recipient who reads with images off — which is precisely the audience
    the alt text exists for.
--}}
<img src="{{ $logo }}" class="logo" alt="{!! trim($slot) !!}">
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
