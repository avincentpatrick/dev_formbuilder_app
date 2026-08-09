{{--
    Inline stylesheet for the printable blank form (Increment I12). The geometry below is
    `docs/ocr-pipeline-design.md` §2.5, and H18's extraction stage is written against it.

    INLINE, not linked, and it is a security property rather than a convenience — the same argument
    `pdf._styles` records: dompdf runs with `isRemoteEnabled = false`, so a <link>, an @font-face or
    a url() would either be ignored or become the file-read/SSRF primitive most of dompdf's published
    advisories are about. There are no url() references, no @font-face and no @import in this file
    and there must never be.

    dompdf implements CSS 2.1 - not flexbox, not grid. Everything below is tables and block boxes on
    purpose; a flex rule here would silently do nothing.

    -- WHY EVERY BOX IS DRAWN IN CSS AND NEVER TYPED AS A GLYPH --------------------------------------
    dompdf's built-in fonts are the PDF core fonts (Helvetica / Times / Courier), which are
    WinAnsi-encoded. U+2610 BALLOT BOX and its filled siblings are NOT in WinAnsi: dompdf drops or
    mangles them, silently, with no error and no warning - a defect visible only by opening the PDF.
    So every checkbox, comb cell and grid cell below is an empty element with a `border`, and
    `BlankFormPrintRendererTest` asserts the whole rendered document round-trips through Windows-1252
    so a glyph cannot creep back in. Captions like DD / MM / YYYY are ASCII for the same reason.

    -- THE COMB GEOMETRY, WHICH IS THE POINT OF THE WHOLE INCREMENT ----------------------------------
    A 14pt cell on a 15.5pt pitch is about 4.94mm of writing width with 0.53mm of separation. Wider
    is more comfortable but fits fewer characters; much narrower and hand-printing degrades, which
    would cost exactly the recognition accuracy the comb is here to buy. 30 cells at this pitch is
    ~164mm against 178mm of usable page, which is where BlankFormPrintPresenter::MAX_COMB_CELLS
    comes from - it is derived from this file, not chosen independently of it.

    -- THE BRAND INTERPOLATIONS (ADR-0014 SS-D8) -----------------------------------------------------
    $brand carries LITERAL light-theme hexes and never `var(--mds-...)`: dompdf cannot resolve a
    custom property at all and would simply drop the declaration, and `oklch()` - the space the ramp
    is derived in - is equally unparseable. Two declarations reference it and every other colour here
    stays achromatic, which is SS-D7's rule ("the tenant layer repaints actions, never neutrals or
    body text") carried onto paper: the boxes a respondent writes in must read the same whatever
    colour the tenant chose. The generator emits `#RRGGBB` and nothing else, so interpolating it into
    a stylesheet is not a new injection surface.
--}}
@page { margin: 22mm 16mm 18mm 16mm; }

body { font-family: sans-serif; font-size: 10pt; line-height: 1.4; color: #1a1a1a; }

/* Repeated on EVERY page by dompdf's fixed-position handling. It has to be a fixed block rather
   than a @page margin box with a counter, because dompdf's page numbering runs through page_text(),
   which is its inline-PHP API, and `isPhpEnabled` is false by security contract. This is the only
   thing that ties page 4 of a scanned stack back to the schema it was printed from. */
/* THE OFFSETS ARE PAGE-CONTENT-RELATIVE, WHICH IS WHY `top` IS NEGATIVE. Read out of the engine
   rather than guessed: Positioner\Absolute::position() places a fixed BLOCK at
   `containing_block + top`, and FrameReflower\AbstractFrameReflower::determine_absolute_containing_block()
   gives a fixed frame the INITIAL containing block — the page box AFTER the @page margins. So with a
   22mm top margin, `top: -14mm` lands 8mm from the paper edge: inside the margin, clear of the
   content. A positive value would print it on top of the first question.

   `width` is explicit for the same reason: that Block branch reads `left` and `top` and NEVER
   `right`, so a `right: 0` would be silently ignored and the box would shrink-to-fit — collapsing
   the floated stamp onto the title instead of setting it at the right margin. */
.runhead {
    position: fixed; top: -14mm; left: 0; width: 100%;
    font-size: 7.5pt; color: #6a6a6a;
    border-bottom: 0.5pt solid #c8c8c8; padding-bottom: 2pt;
}
.runhead__stamp { float: right; font-family: monospace; }

.head { border-bottom: 1.5pt solid {{ $brand['bg'] }}; padding-bottom: 6pt; margin-bottom: 12pt; }
.head h1 { font-size: 16pt; margin: 0 0 4pt 0; color: {{ $brand['bg'] }}; }
.head__desc { margin: 0 0 4pt 0; font-size: 9pt; color: #3a3a3a; }
.head__meta { margin: 0; font-size: 8pt; color: #5a5a5a; }

/* Keep a heading with at least the start of its block rather than orphaning it at a page foot. */
.block { margin-bottom: 10pt; page-break-inside: auto; }
.block h2 {
    font-size: 11pt; margin: 0 0 5pt 0; padding-bottom: 2pt;
    border-bottom: 0.5pt solid #c8c8c8; page-break-after: avoid;
}
.block__desc { margin: 0 0 5pt 0; font-size: 8.5pt; color: #5a5a5a; }

/* One block-level box per question so dompdf paginates BETWEEN questions. It splits a run of
   sibling block boxes far more reliably than it splits one long table, which is the same reasoning
   pdf._styles records for the submission document's one-table-per-row layout. */
.q { margin-bottom: 7pt; page-break-inside: avoid; }
.q__label { font-size: 9.5pt; color: #1a1a1a; margin: 0 0 2pt 0; }
.q__key { float: right; font-family: monospace; font-size: 7pt; color: #9a9a9a; }
.q__req { color: #b3261e; }
.q__flag { font-size: 7.5pt; color: #6a6a6a; }
.q__hint { margin: 0 0 3pt 0; font-size: 8pt; color: #5a5a5a; }
.q__note { margin: 0; font-size: 8.5pt; color: #6a6a6a; font-style: italic; }

/* -- Comb ------------------------------------------------------------------------------------- */
/* `border-spacing` is what separates the cells, so `border-collapse` MUST stay `separate`: collapsing
   merges every cell wall into one continuous ruled line and the comb stops being a comb. */
.comb { border-collapse: separate; border-spacing: 1.5pt 0; margin: 0; }
.comb td { width: 14pt; height: 16pt; border: 0.6pt solid #7a7a7a; padding: 0; }
.comb__gap { width: 7pt; border: none; }
.comb__caption td { border: none; height: auto; font-size: 6.5pt; color: #8a8a8a; text-align: center; }

/* -- Ruled ------------------------------------------------------------------------------------ */
.ruled { border: 0.6pt solid #7a7a7a; height: 46pt; }

/* -- Choices ---------------------------------------------------------------------------------- */
.choice { margin: 1pt 0 1pt 0; font-size: 9pt; }
.choice__box {
    display: inline-block; width: 10pt; height: 10pt;
    border: 0.6pt solid #7a7a7a; margin-right: 4pt;
}

/* -- Grid ------------------------------------------------------------------------------------- */
.grid { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
.grid th, .grid td { border: 0.5pt solid #9a9a9a; padding: 3pt; }
.grid th { background-color: {{ $brand['tint'] }}; font-weight: normal; text-align: center; }
.grid__row { text-align: left; }
.grid__cell { text-align: center; }
.grid__box {
    display: inline-block; width: 9pt; height: 9pt; border: 0.6pt solid #7a7a7a;
}

/* -- Page break ------------------------------------------------------------------------------- */
/* An authored `page_break` field. The one structural field type that means MORE on paper than on a
   screen, so it is honoured literally rather than dropped. */
.pagebreak { page-break-before: always; }

/* -- Signature -------------------------------------------------------------------------------- */
/* A line, not a comb: nobody signs inside boxes. Bottom border only, and tall enough for a
   descender to clear it. */
.sign { border-bottom: 0.6pt solid #7a7a7a; height: 30pt; width: 60%; }

.foot {
    margin-top: 14pt; padding-top: 6pt; border-top: 0.5pt solid #c8c8c8;
    font-size: 8pt; color: #6a6a6a;
}
.foot p { margin: 0 0 2pt 0; }
