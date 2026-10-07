{{--
    Inline stylesheet for the printable blank form (Increment I12; layout 2 since M143, `D96`). The
    geometry below is `docs/ocr-pipeline-design.md` §2.5, and the OCR reader is written against it.

    INLINE, not linked, and it is a security property rather than a convenience — the same argument
    `pdf._styles` records: dompdf runs with `isRemoteEnabled = false`, so a <link>, an @font-face or
    a url() would either be ignored or become the file-read/SSRF primitive most of dompdf's published
    advisories are about. There are no url() references, no @font-face and no @import in this file
    and there must never be.

    dompdf implements CSS 2.1 - not flexbox, not grid. Everything below is tables, block boxes and
    inline-block spans on purpose; a flex rule here would silently do nothing.

    -- WHY EVERY BOX IS DRAWN IN CSS AND NEVER TYPED AS A GLYPH --------------------------------------
    dompdf's built-in fonts are the PDF core fonts (Helvetica / Times / Courier), which are
    WinAnsi-encoded. U+2610 BALLOT BOX and its filled siblings are NOT in WinAnsi: dompdf drops or
    mangles them, silently, with no error and no warning - a defect visible only by opening the PDF.
    So every checkbox, comb cell and grid cell below is an empty element with a `border`, and
    `BlankFormPrintRendererTest` asserts the whole rendered document round-trips through Windows-1252
    so a glyph cannot creep back in. Captions like DD / MM / YYYY are ASCII for the same reason.

    -- LAYOUT 2: THE GEOMETRY A PEN AND A MONOCHROME PRINTER ASKED FOR -------------------------------
    The first paper (2026-08-09) combed every text answer into 14pt boxes and listed choices one per
    line; the user who printed it found it unorganized and the boxes limiting. Layout 2 follows the
    ICR form-design guidance instead: a comb cell is 18pt SQUARE (about 6.3mm, pen-sized) on a 20pt
    pitch, used only where the answer is digits — a number, a phone, a date or a time; short text gets
    one open box; a choice box is 14pt (about 4.9mm, above the 3.5-4mm OMR floor) and the options sit
    side by side; every question is numbered; a boxed instruction banner tells the respondent that a
    computer reads the sheet. 23 cells at this pitch is the page's ceiling —
    BlankFormPrintPresenter::MAX_COMB_CELLS derives it from the numbers in this file, including the
    `border-spacing` dompdf lands at BOTH table edges.

    -- LAYOUT 3 (M144, D98): DIGITS IN AN OPEN BOX, THE LONG-TEXT BOX SIZED FROM ITS MAX_LENGTH --------
    The user's two comments on layout 2. A comb now exists only under captions (a date, a time, a
    duration, a cascade's levels): a free run of digits - a number, a phone - reads as well from one
    open box, and the reader parses it out of the text. The long-text box is `lines` x 20pt, three
    lines (layout 2's 60pt) to ten, from the question's max_length at about 45 hand-printed capitals
    a line; the banner tells the respondent to continue on another sheet when a box runs out.

    -- MONOCHROME, SO NO BRAND COLOUR (D96; ADR-0014 SS-D8 note of 2026-10-07) ------------------------
    The paper is printed in black most of the time, and a tenant colour that dithers to grey helps
    neither the respondent nor the reader. Every colour below is black or a grey dark enough to print
    solid; the two `$brand` interpolations the first layout carried are gone, and the renderer no
    longer passes a palette.
--}}
@page { margin: 22mm 16mm 18mm 16mm; }

body { font-family: sans-serif; font-size: 10pt; line-height: 1.4; color: #000000; }

/* Repeated on EVERY page by dompdf's fixed-position handling. It has to be a fixed block rather
   than a @page margin box with a counter, because dompdf's page numbering runs through page_text(),
   which is its inline-PHP API, and `isPhpEnabled` is false by security contract. This is the only
   thing that ties page 4 of a scanned stack back to the schema AND the layout it was printed from. */
/* THE OFFSETS ARE PAGE-CONTENT-RELATIVE, WHICH IS WHY `top` IS NEGATIVE. Read out of the engine
   rather than guessed: Positioner\Absolute::position() places a fixed BLOCK at
   `containing_block + top`, and FrameReflower\AbstractFrameReflower::determine_absolute_containing_block()
   gives a fixed frame the INITIAL containing block — the page box AFTER the @page margins. So with a
   22mm top margin, `top: -14mm` lands 8mm from the paper edge: inside the margin, clear of the
   content. A positive value would print it on top of the first question.

   `width` is explicit for the same reason: that Block branch reads `left` and `top` and NEVER
   `right`, so a `right: 0` would be silently ignored and the box would shrink-to-fit — collapsing
   the floated stamps onto the title instead of setting them at the right margin. */
.runhead {
    position: fixed; top: -14mm; left: 0; width: 100%;
    font-size: 8pt; color: #333333;
    border-bottom: 0.75pt solid #333333; padding-bottom: 2pt;
}
/* Two floats, the stamp FIRST in source order so it lands rightmost; the page then reads
   "Layout 2  a1b2c3d4" left to right. Bold black monospace: the reader finds the version by the
   8-hex stamp and the template by the layout word, and both have to survive a phone photo. */
.runhead__stamp, .runhead__layout {
    float: right; font-family: monospace; font-size: 9pt; font-weight: bold; color: #000000;
}
.runhead__layout { margin-right: 8pt; }

.head { border-bottom: 1pt solid #000000; padding-bottom: 6pt; margin-bottom: 8pt; }
.head h1 { font-size: 16pt; margin: 0 0 4pt 0; color: #000000; }
.head__desc { margin: 0 0 4pt 0; font-size: 9pt; color: #333333; }
.head__meta { margin: 0; font-size: 8pt; color: #333333; }

/* -- The instruction banner ------------------------------------------------------------------- */
/* The ICR guidance's one non-negotiable once text answers are written freely: say, in bold, at the
   top, that a computer reads the form, ask for BLOCK CAPITALS, and show what a filled box and a
   marked choice look like. It sits above every question's anchor, so the reader never sees it
   inside an answer region — which is also why it must NOT be added to the matcher's static list
   (see PrintedFormMatcher::match()). */
.banner { border: 1pt solid #000000; padding: 5pt 7pt; margin: 0 0 10pt 0; font-size: 9pt; }
.banner__rule { margin: 0 0 4pt 0; font-weight: bold; }
.banner__sample { border-collapse: collapse; margin: 0; }
.banner__sample td { padding: 0 14pt 0 0; vertical-align: middle; font-size: 8.5pt; color: #333333; }
.banner__sample .comb td { padding: 0; font-size: 10pt; color: #000000; text-align: center; font-weight: bold; }

/* Keep a heading with at least the start of its block rather than orphaning it at a page foot. */
.block { margin-bottom: 10pt; page-break-inside: auto; }
.block h2 {
    font-size: 11pt; margin: 0 0 5pt 0; padding-bottom: 2pt; color: #000000;
    border-bottom: 0.5pt solid #333333; page-break-after: avoid;
}
.block__desc { margin: 0 0 5pt 0; font-size: 8.5pt; color: #333333; }

/* One block-level box per question so dompdf paginates BETWEEN questions. It splits a run of
   sibling block boxes far more reliably than it splits one long table, which is the same reasoning
   pdf._styles records for the submission document's one-table-per-row layout. 12pt between
   questions is more than half a cell height, the guidance's floor for keeping answers apart. */
.q { margin-bottom: 12pt; page-break-inside: avoid; }
.q__label { font-size: 10pt; color: #000000; margin: 0 0 3pt 0; }
.q__num { font-weight: bold; margin-right: 4pt; }
/* 8pt and a dark grey, up from 7pt light grey: the stamp is what the reader anchors a question on,
   and a monochrome printer dithers a light grey into something a phone photo loses. */
.q__key { float: right; font-family: monospace; font-size: 8pt; color: #444444; }
.q__req { color: #000000; font-weight: bold; }
.q__flag { font-size: 7.5pt; color: #333333; }
.q__hint { margin: 0 0 3pt 0; font-size: 8pt; color: #333333; }
.q__note { margin: 0; font-size: 8.5pt; color: #333333; font-style: italic; }

/* NOT `.q__note`, though the two started identical. A note is prose the respondent is meant to READ
   (a consent paragraph, an instruction); this is an apology for a missing answer area. Sharing a
   class would have made them indistinguishable on the page and welded the two together the first
   time either needed to change. The left rule is what separates them by eye. */
.q__unavailable {
    margin: 0; padding-left: 5pt; font-size: 8pt; color: #555555;
    border-left: 1.5pt solid #555555;
}

/* -- Comb ------------------------------------------------------------------------------------- */
/* `border-spacing` is what separates the cells, so `border-collapse` MUST stay `separate`: collapsing
   merges every cell wall into one continuous ruled line and the comb stops being a comb. The 2pt
   spacing lands at both table edges as well as between cells, which MAX_COMB_CELLS accounts for. */
.comb { border-collapse: separate; border-spacing: 2pt 0; margin: 0; }
.comb td { width: 18pt; height: 18pt; border: 0.75pt solid #333333; padding: 0; }
.comb__gap { width: 10pt; border: none; }
.comb__caption td { border: none; height: auto; font-size: 8pt; color: #333333; text-align: center; }

/* -- Line and Ruled --------------------------------------------------------------------------- */
/* One open box for a short text answer (layout 2), and for a phone or a number since layout 3: 26pt
   is about 9mm, a comfortable line of block capitals. The long-text box is N lines at a 20pt pitch
   with no inner rules — a rule reads as ink; the template sets its height inline from the presenter's
   line count (three to ten, layout 3), and the 60pt here is the fallback for the optionless choice
   list's write-in box, whose row carries no count. */
.line { border: 0.75pt solid #333333; height: 26pt; }
.ruled { border: 0.75pt solid #333333; height: 60pt; }

/* -- Choices ---------------------------------------------------------------------------------- */
/* Side by side, wrapping between options. inline-block is the one layout primitive dompdf offers
   for this; `white-space: nowrap` keeps a box with its own label when the row wraps. 18pt between
   options is more than a box width, so a mark cannot be read as belonging to the label after it. */
/* `.choices` is the row itself: a block below the label with its own top margin and a taller
   line, because dompdf centres a 14pt inline-block on a 10pt text line and lets it rise above the
   line top — without the row's own space the first box runs into the label above it. */
.choices { margin-top: 6pt; line-height: 2.0; }
.choice { display: inline-block; white-space: nowrap; margin: 0 18pt 0 0; font-size: 10pt; }
.choice__box {
    display: inline-block; width: 14pt; height: 14pt;
    border: 0.75pt solid #333333; margin-right: 5pt; vertical-align: middle;
}
/* The banner's worked example only: an X drawn inside the box, in the typeface, never a glyph. */
.choice__box--sample { text-align: center; font-size: 10pt; line-height: 14pt; font-weight: bold; color: #000000; }

/* -- Grid ------------------------------------------------------------------------------------- */
.grid { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
.grid th, .grid td { border: 0.5pt solid #333333; padding: 3pt; }
.grid th { background-color: #e6e6e6; font-weight: normal; text-align: center; }
.grid__row { text-align: left; }
.grid__cell { text-align: center; }
.grid__box {
    display: inline-block; width: 12pt; height: 12pt; border: 0.75pt solid #333333;
}

/* -- Page break ------------------------------------------------------------------------------- */
/* An authored `page_break` field. The one structural field type that means MORE on paper than on a
   screen, so it is honoured literally rather than dropped. */
.pagebreak { page-break-before: always; }

/* -- Signature -------------------------------------------------------------------------------- */
/* A line, not a comb: nobody signs inside boxes. Bottom border only, and tall enough for a
   descender to clear it. */
.sign { border-bottom: 0.75pt solid #333333; height: 30pt; width: 60%; }

.foot {
    margin-top: 14pt; padding-top: 6pt; border-top: 0.5pt solid #333333;
    font-size: 8pt; color: #333333;
}
.foot p { margin: 0 0 2pt 0; }
