{{--
    Inline stylesheet for the submission PDF (Increment H17).

    INLINE, not linked, and that is a security property rather than a convenience: dompdf runs with
    `isRemoteEnabled = false`, so a <link> or an @font-face or a url() would either be ignored or —
    in older dompdf — become the file-read and SSRF primitive that most of its 20 published
    advisories are about. There are no url() references, no @font-face and no @import in this file
    and there must never be.

    Fonts are dompdf's built-in PDF core fonts (Helvetica / Times / Courier metrics), which need no
    font file, no font cache and no `ext-gd`. `sans-serif` resolves to Helvetica.

    dompdf implements CSS 2.1, not flexbox or grid. Layout below is tables and block boxes on
    purpose; a flex rule here would silently do nothing.

    ── THE BRAND INTERPOLATIONS (H23a4, ADR-0014 §D8) ──────────────────────────────────────────────
    $brand carries LITERAL light-theme hexes and never `var(--mds-…)`, because dompdf implements CSS 2.1
    and cannot resolve a custom property at all — the declaration would simply be dropped and the element
    would render with no colour. `oklch()`, the space the ramp is derived in, is equally unparseable here.
    That pair of facts is half of why §D8 stores the ramp instead of re-deriving it on read.

    THREE declarations reference $brand, and every other colour in this file stays achromatic. That is
    §D7's rule ("the tenant layer repaints actions, never neutrals or body text") carried onto paper: a
    respondent's answers must read the same whatever colour the tenant chose.

    Each one is justified by a pairing the engine ALREADY MEASURED, not by eye:
      - `bg` on white paper is `bg`/on_primary read backwards. Contrast is symmetric, so the >= 4.5:1
        the generator proved for white text on the fill is exactly the ratio brand-coloured ink has
        against the page.
      - `tint` under ink is the `tint`/ink pairing verbatim — which is why .prose names #121A2A rather
        than inheriting the body's #1a1a1a: #121A2A IS the light ink ground that measurement was taken
        against, so naming it is what makes the stored ratio true of this element rather than approximately
        true.

    The section-heading hairline (.block h2) stays GREY on purpose. Colouring it too was considered and
    dropped: the brand then repeats on every section of a long document, which reads as decoration rather
    than identity, and the .head rule already establishes whose document this is. One line, at the top.
--}}
@page { margin: 18mm 16mm 20mm 16mm; }

body { font-family: sans-serif; font-size: 10pt; line-height: 1.45; color: #1a1a1a; }

.head { border-bottom: 1.5pt solid {{ $brand['bg'] }}; padding-bottom: 6pt; margin-bottom: 14pt; }
.head h1 { font-size: 16pt; margin: 0 0 8pt 0; color: {{ $brand['bg'] }}; }

.meta { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
.meta td { padding: 1.5pt 0; vertical-align: top; }
.meta__k { width: 14%; color: #5a5a5a; text-transform: uppercase; letter-spacing: 0.4pt; }
.meta__v { width: 36%; }

.block { margin-bottom: 12pt; }
.block h2 {
    font-size: 11pt; margin: 0 0 5pt 0; padding-bottom: 2pt;
    border-bottom: 0.5pt solid #c8c8c8;
}

/* One table per row rather than one table for the block: dompdf paginates between block-level
   boxes far more reliably than it splits a single long table, so this keeps a question and its
   answer together on one page instead of orphaning the answer. */
.qa { width: 100%; border-collapse: collapse; margin-bottom: 3pt; }
.qa__q { width: 46%; padding: 2.5pt 8pt 2.5pt 0; color: #3a3a3a; vertical-align: top; }
.qa__a { width: 54%; padding: 2.5pt 0; vertical-align: top; }

.prose {
    margin: 4pt 0 6pt 0; padding: 5pt 7pt; font-size: 9.5pt;
    background-color: {{ $brand['tint'] }}; color: #121A2A;
}
.notice { margin: 2pt 0; color: #6a6a6a; font-style: italic; }

.foot {
    margin-top: 16pt; padding-top: 6pt; border-top: 0.5pt solid #c8c8c8;
    font-size: 8pt; color: #6a6a6a;
}
.foot p { margin: 0 0 2pt 0; }
