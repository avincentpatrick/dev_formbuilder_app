{{--
    The Meridian mail theme (H23a4) — the design system's stylesheet for transactional email.

    ── WHY THIS IS BLADE AND NOT `.css` ────────────────────────────────────────────────────────────
    `Markdown::render()` resolves a theme named `meridian` to the view `mail.meridian` and RENDERS IT AS A
    VIEW with the same data the message got, so `$brand` is in scope here. That is the only mechanism by
    which a per-tenant colour can reach a mail stylesheet — the file is read through the view finder, whose
    extension order is `blade.php, php, css, html`, and a `.css` view is mapped to the FileEngine and
    served RAW. A `themes/meridian.css` would therefore emit the literal text `{{ $brand['bg'] }}` into
    every email.

    ── WHY LITERAL HEXES AND NOT `var(--mds-…)` ────────────────────────────────────────────────────
    ADR-0014 §D8: mail clients strip `<style>` blocks and ignore custom properties entirely. This file is
    inlined onto the elements by CssToInlineStyles before it is sent — there is no cascade left to resolve
    a variable against. The neutrals below are `packages/design-system` values transcribed by hand for
    that reason, and they are the ONLY place in mail that carries them.

    ── WHAT THE TENANT CONTROLS, AND WHAT IT DOES NOT ──────────────────────────────────────────────
    ADR-0014 §D7 carried into the mail surface: the brand paints ACTIONS — the button, links, the panel
    accent and its tint — and never neutrals, body text or headings. Six declarations reference `$brand`
    and every other colour here is fixed. `.button`'s text stays `#FFFFFF` (the generator's ON_PRIMARY),
    never `$brand['fg']`, because in the light ramp `fg` IS `bg` and that would put the fill on the fill.

    NO `@media` BLOCKS, EVER: `CssToInlineStyles` deletes them from the theme before inlining, so a rule
    written here would silently never reach a client. The framework's layout carries the responsive rules
    in a `<style>` tag instead, which survives — see BrandPalette for why dark mode is not attempted.
--}}
/* Base */

body,
body *:not(html):not(style):not(br):not(tr):not(code) {
    box-sizing: border-box;
    font-family: -apple-system, 'Segoe UI', 'San Francisco', 'Helvetica Neue', Arial, sans-serif;
    position: relative;
}

body {
    -webkit-text-size-adjust: none;
    background-color: #FFFFFF;
    color: #16212B;
    height: 100%;
    line-height: 1.4;
    margin: 0;
    padding: 0;
    width: 100% !important;
}

p,
ul,
ol,
blockquote {
    line-height: 1.4;
    text-align: start;
}

a {
    color: {{ $brand['bg'] }};
}

a img {
    border: none;
}

/* Typography */

h1 {
    color: #0E1620;
    font-family: 'Segoe UI Variable Display', system-ui, -apple-system, 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
    font-size: 18px;
    font-weight: bold;
    margin-top: 0;
    text-align: start;
}

h2 {
    color: #0E1620;
    font-size: 16px;
    font-weight: bold;
    margin-top: 0;
    text-align: start;
}

h3 {
    color: #0E1620;
    font-size: 14px;
    font-weight: bold;
    margin-top: 0;
    text-align: left;
}

p {
    font-size: 16px;
    line-height: 1.5em;
    margin-top: 0;
    text-align: left;
}

p.sub {
    font-size: 12px;
}

img {
    max-width: 100%;
}

/* Layout */

.wrapper {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    background-color: #F3F4F1;
    margin: 0;
    padding: 0;
    width: 100%;
}

.content {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 0;
    padding: 0;
    width: 100%;
}

/* Header */

.header {
    padding: 25px 0;
    text-align: center;
}

.header a {
    color: {{ $brand['bg'] }};
    font-family: 'Segoe UI Variable Display', system-ui, -apple-system, 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
    font-size: 19px;
    font-weight: bold;
    text-decoration: none;
}

/* Logo */

/* A tenant wordmark is a horizontal lockup, not the 75x75 square the framework's theme assumes for its
   own logo. Bounding the HEIGHT and leaving the width automatic is what keeps an uploaded logo of any
   aspect ratio from either blowing out the header or being squashed into a square. */
.logo {
    height: auto;
    margin-top: 4px;
    margin-bottom: 4px;
    max-height: 44px;
    max-width: 260px;
    width: auto;
}

/* Body */

.body {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    background-color: #F3F4F1;
    border-bottom: 1px solid #F3F4F1;
    border-top: 1px solid #F3F4F1;
    margin: 0;
    padding: 0;
    width: 100%;
}

.inner-body {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 570px;
    background-color: #FFFFFF;
    border-color: #DDE1DA;
    border-radius: 8px;
    border-width: 1px;
    box-shadow: 0 1px 3px 0 rgba(14, 22, 32, 0.08), 0 1px 2px -1px rgba(14, 22, 32, 0.06);
    margin: 0 auto;
    padding: 0;
    width: 570px;
}

.inner-body a {
    word-break: break-all;
}

/* Subcopy */

.subcopy {
    border-top: 1px solid #DDE1DA;
    margin-top: 25px;
    padding-top: 25px;
}

.subcopy p {
    font-size: 14px;
}

/* Footer */

.footer {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 570px;
    margin: 0 auto;
    padding: 0;
    text-align: center;
    width: 570px;
}

.footer p {
    color: #4A5A66;
    font-size: 12px;
    text-align: center;
}

.footer a {
    color: #4A5A66;
    text-decoration: underline;
}

/* Tables */

.table table {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 30px auto;
    width: 100%;
}

.table th {
    border-bottom: 1px solid #DDE1DA;
    margin: 0;
    padding-bottom: 8px;
}

.table td {
    color: #16212B;
    font-size: 15px;
    line-height: 18px;
    margin: 0;
    padding: 10px 0;
}

.content-cell {
    max-width: 100vw;
    padding: 32px;
}

/* Buttons */

.action {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 30px auto;
    padding: 0;
    text-align: center;
    width: 100%;
    float: unset;
}

.button {
    -webkit-text-size-adjust: none;
    border-radius: 6px;
    color: #FFFFFF;
    display: inline-block;
    font-weight: 600;
    overflow: hidden;
    text-decoration: none;
}

/* The one place a tenant's colour becomes the loudest thing in the message. The borders repeat the fill
   because that is how this theme makes a button's padding survive clients that drop `padding` on an <a>. */
.button-blue,
.button-primary {
    background-color: {{ $brand['bg'] }};
    border-bottom: 8px solid {{ $brand['bg'] }};
    border-left: 18px solid {{ $brand['bg'] }};
    border-right: 18px solid {{ $brand['bg'] }};
    border-top: 8px solid {{ $brand['bg'] }};
}

/* Success and error stay MERIDIAN's semantic colours, not the tenant's: a "success" button is reporting
   an outcome, and letting a brand repaint it would make green mean "brand" instead of "it worked".
   ADR-0011 §D11 made the same call for chart colours. */
.button-green,
.button-success {
    background-color: #2F6249;
    border-bottom: 8px solid #2F6249;
    border-left: 18px solid #2F6249;
    border-right: 18px solid #2F6249;
    border-top: 8px solid #2F6249;
}

.button-red,
.button-error {
    background-color: #A83A2A;
    border-bottom: 8px solid #A83A2A;
    border-left: 18px solid #A83A2A;
    border-right: 18px solid #A83A2A;
    border-top: 8px solid #A83A2A;
}

/* Panels */

.panel {
    border-left: {{ $brand['bg'] }} solid 4px;
    margin: 21px 0;
}

/* `tint` against ink is one of the seventeen pairings the engine measures (>= 4.5:1), and #0E1620 IS the
   light ink ground it measured against — so naming it here rather than inheriting the body's #16212B is
   what makes the stored measurement true of this element. */
.panel-content {
    background-color: {{ $brand['tint'] }};
    color: #0E1620;
    padding: 16px;
}

.panel-content p {
    color: #0E1620;
}

.panel-item {
    padding: 0;
}

.panel-item p:last-of-type {
    margin-bottom: 0;
    padding-bottom: 0;
}

/* Utilities */

.break-all {
    word-break: break-all;
}
