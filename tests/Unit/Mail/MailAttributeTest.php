<?php

declare(strict_types=1);

use App\Support\Mail\MailAttribute;

/**
 * The markdown-mail attribute escaper (M57), pinned at the unit level.
 *
 * `BrandedMailRenderTest` proves the behaviour through a real render, which is the assertion that
 * matters. This file exists for the one property that render cannot isolate: `CssToInlineStyles`
 * re-serialises the document and repairs a bare `&` on its own, so a render-only suite would stay green
 * against an escaper built at the DEFAULT `double_encode: true` — the remedy this increment measured and
 * rejected. Here there is no DOM in the way.
 */
it('escapes the quote that breaks an attribute', function (): void {
    expect(MailAttribute::escape('Acme" onerror="alert(1)'))
        ->toBe('Acme&quot; onerror=&quot;alert(1)');
});

it('escapes the single quote too, so a single-quoted attribute is equally safe', function (): void {
    expect(MailAttribute::escape("Acme' onerror='alert(1)"))
        ->toBe('Acme&#039; onerror=&#039;alert(1)');
});

it('leaves an already-escaped entity alone while still escaping a bare ampersand', function (): void {
    // The load-bearing argument, with both halves in one vector because they are the same call pulling
    // in opposite directions. Values arrive here AFTER `Markdown::withSecuredEncoding()`'s
    // three-character map, so `<C>` is already `&lt;C&gt;` and must survive untouched
    // (`double_encode: false`) — at the default it returns `&amp;lt;C&amp;gt;` and every images-off
    // reader sees the entities as literal text. The bare `&` must still become `&amp;`, or the call is
    // a no-op wearing a name.
    expect(MailAttribute::escape('A & B &lt;C&gt;'))->toBe('A &amp; B &lt;C&gt;');
});

it('substitutes invalid UTF-8 rather than returning an empty string', function (): void {
    // ENT_SUBSTITUTE, not the PHP default. Without it htmlspecialchars returns '' for a malformed
    // sequence, so one bad byte in a tenant name would silently empty the alt text of every email that
    // tenant sends rather than degrading it.
    expect(MailAttribute::escape("Acme\xB1Health"))->not->toBe('');
});
