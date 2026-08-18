{{--
    The printable BLANK form (Increment I12) - one published version, typeset for a pen.

    -- THE ESCAPING CONTRACT, WHICH IS THE POINT OF THIS FILE ----------------------------------------
    EVERY interpolation below uses the escaping braces, which is Blade's e() -> htmlspecialchars($v,
    ENT_QUOTES, 'UTF-8') - exactly the escaper `docs/piping-output-encoding-design.md` SS5 names for
    this surface. Blade's RAW-OUTPUT directive appears nowhere in this file and must never be added;
    SubmissionPdfRendererTest scans every template in this directory for it, which is why the token is
    described here rather than written out.

    Everything on this page is tenant-authored free text: the form title, its description, every
    section heading and description, every field label and hint, and every option label on every
    choice list and both grid axes. SS5 decision (3) binds all of them.

    Unlike the submission PDF there is no respondent input here at all - a blank form is printed
    before anybody has answered anything - so the threat is a form AUTHOR forging the structure of a
    document their own field staff will treat as an instrument. Same escaper, different actor.

    -- ASCII ONLY, AND IT IS LOAD-BEARING -----------------------------------------------------------
    dompdf's built-in fonts are WinAnsi-encoded, so a glyph like U+2610 BALLOT BOX drops or mangles
    SILENTLY. Every box on this page is therefore an empty CSS-bordered element and every literal
    string this template contributes is ASCII. BlankFormPrintRendererTest renders an ASCII-only
    fixture and asserts the whole output round-trips through Windows-1252, so a glyph pasted in later
    reddens rather than shipping as a visual defect nobody sees until they open a PDF.

    -- NO IMAGES OF ANY KIND ------------------------------------------------------------------------
    No <img>, no <link>, no @font-face, no url(). dompdf runs with `isRemoteEnabled = false` and
    `ext-gd` is absent from the app container and from every CI job, so a logo, a barcode or a QR
    would render on a developer's machine and throw in the pipeline. The version identity a scanning
    stage needs travels as printed TEXT in `.runhead`, repeated on every page.
--}}
<!DOCTYPE html>
<html lang="{{ $model['locale'] ?? 'en' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $model['form_title'] }}</title>
    <style>@include('pdf._blank-form-styles')</style>
</head>
<body>
    {{-- Repeated on every page. Identifies the schema a loose scanned sheet came from. --}}
    <div class="runhead">
        @if ($model['schema_stamp'] !== null)
            <span class="runhead__stamp">{{ $model['schema_stamp'] }}</span>
        @endif
        {{-- No HTML entity here, deliberately: an entity is ASCII in the source and would sail
             through a round-trip check on the markup while still handing dompdf a glyph its
             WinAnsi fonts cannot draw. Separators in this file are plain ASCII. --}}
        {{ $model['form_title'] }} / v{{ $model['version_number'] }}
    </div>

    <header class="head">
        <h1>{{ $model['form_title'] }}</h1>

        @if ($model['form_description'] !== null && $model['form_description'] !== '')
            <p class="head__desc">{{ $model['form_description'] }}</p>
        @endif

        <p class="head__meta">
            Version {{ $model['version_number'] }}@if ($model['published_at'] !== null), published {{ $model['published_at'] }}@endif.
            Please write in CAPITAL LETTERS, one character per box.
        </p>
    </header>

    @foreach ($model['blocks'] as $block)
        <section class="block">
            @if ($block['label'] !== null && $block['label'] !== '')
                {{-- The directives are spaced apart on purpose: Blade's compiler does not match an
                     `@if` written immediately against a preceding `@endif`, and leaves the second
                     one in the output as literal text — which then fails as a PHP syntax error in
                     the COMPILED view, several frames away from the line that caused it. --}}
                <h2>
                    {{ $block['label'] }}
                    @if ($block['instance'] !== null){{ $block['instance'] }}@endif
                    @if ($block['conditional'])<span class="q__flag">(if applicable)</span>@endif
                </h2>
            @endif

            @if ($block['description'] !== null)
                <p class="block__desc">{{ $block['description'] }}</p>
            @endif

            @foreach ($block['fields'] as $field)
                @if ($field['area'] === 'page_break')
                    {{-- An authored page break. Emitted as an empty block box rather than wrapped in
                         a question, because it asks nothing. --}}
                    <div class="pagebreak"></div>
                @elseif ($field['area'] === 'prose')
                    {{-- A note: instructions, a consent paragraph, a section preamble. Printed
                         because the person filling the form has to read it; no answer area, because
                         it asks for nothing. --}}
                    <p class="q__note">{{ $field['label'] }}</p>
                @else
                    <div class="q">
                        <p class="q__label">
                            {{-- Floated, so it must come FIRST in source order to sit on the label's
                                 own line. Printed small and grey: it is for the extraction stage and
                                 for anyone reconciling a scan, not for the respondent. --}}
                            <span class="q__key">{{ $field['key'] }}</span>
                            {{ $field['label'] }}@if ($field['required'])<span class="q__req">*</span>@endif
                            @if ($field['conditional'])<span class="q__flag">(if applicable)</span>@endif
                        </p>

                        @if ($field['hint'] !== null)
                            <p class="q__hint">{{ $field['hint'] }}</p>
                        @endif

                        @if ($field['area'] === 'comb')
                            @include('pdf._blank-form-comb', ['groups' => $field['comb']])
                        @elseif ($field['area'] === 'ruled')
                            <div class="ruled"></div>
                        @elseif ($field['area'] === 'choices')
                            {{-- @forelse, not @foreach: a choice field with no options cannot be
                                 published (StructuralValidationGate refuses it), but a hand-built
                                 snapshot can carry one, and @foreach would print a labelled question
                                 with NOWHERE TO ANSWER IT. A ruled box degrades to "write it in"
                                 rather than to a dead end. --}}
                            @forelse ($field['options'] as $option)
                                <div class="choice"><span class="choice__box"></span>{{ $option['label'] }}</div>
                            @empty
                                <div class="ruled"></div>
                            @endforelse
                        @elseif ($field['area'] === 'grid')
                            <table class="grid">
                                <tr>
                                    <th class="grid__row"></th>
                                    @foreach ($field['grid']['columns'] as $column)
                                        <th>{{ $column['label'] }}</th>
                                    @endforeach
                                </tr>
                                @foreach ($field['grid']['rows'] as $row)
                                    <tr>
                                        <td class="grid__row">{{ $row['label'] }}</td>
                                        @foreach ($field['grid']['columns'] as $column)
                                            <td class="grid__cell"><span class="grid__box"></span></td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </table>
                        @elseif ($field['area'] === 'signature_line')
                            <div class="sign"></div>
                        @elseif ($field['area'] === 'unavailable')
                            {{-- Named, marked, given no writing area. Omitting it entirely would
                                 leave an enumerator with no prompt to capture the reading by another
                                 means; a writable box would invite them to write into an area
                                 nothing will ever read. --}}
                            <p class="q__unavailable">Not collected on paper - record this in the app.</p>
                        @endif
                    </div>
                @endif
            @endforeach
        </section>
    @endforeach

    <footer class="foot">
        {{-- States what the document IS. A blank form shows every question the version defines,
             including the ones the form's own logic would hide from a given respondent on screen -
             the person holding the paper has to be able to see a branch in order to follow it. --}}
        <p>This is a blank copy of every question in version {{ $model['version_number'] }}. Questions
            marked "(if applicable)" depend on earlier answers.</p>
        @if ($model['ocr_compatible'])
            <p>Scans of this form can be read automatically.</p>
        @else
            <p>Scans of this form cannot be read automatically; responses must be keyed in.</p>
        @endif
    </footer>
</body>
</html>
