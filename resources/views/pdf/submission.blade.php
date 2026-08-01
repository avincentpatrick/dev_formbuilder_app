{{--
    The submission PDF (Increment H17) — the first server-rendered HTML surface in this codebase
    outside the two Inertia root shells, and the surface `docs/piping-output-encoding-design.md`
    §5's table row for "Queued PDF (H17)" binds.

    ── THE ESCAPING CONTRACT, WHICH IS THE POINT OF THIS FILE ──────────────────────────────────
    EVERY interpolation below uses `{{ }}`, which is Blade's `e()` → `htmlspecialchars($v,
    ENT_QUOTES, 'UTF-8')` — exactly the escaper §5 names for this surface.

    Blade's RAW-OUTPUT directive (the unescaped-braces form) appears nowhere in this file and must
    never be added — `SubmissionPdfRendererTest` scans every template in this directory for it,
    which is why the token is described here rather than written out. Doc #26 records that zero
    raw-output directives exist in application code today, and this document is rendered entirely
    from tenant- and respondent-supplied strings: the form title, every section heading, every
    field label (which
    may itself have had a respondent's answer piped INTO it) and every answer value. §5 decision
    (3) binds all of them, not only the piped ones — the named failure mode is escaping the answer
    while leaving the form title raw beside it in the same document.

    dompdf's own HTML parser would happily execute nothing (no JS engine, and `isJavascriptEnabled`
    is false), but the threat here is not script execution — it is a respondent controlling the
    document's structure and typography to forge content in a record a tenant treats as evidence.

    No external references of any kind: no <img src>, no <link>, no @font-face, no url(). dompdf
    runs with `isRemoteEnabled = false`, and `ext-gd` is not installed in the app container, so a
    remote or raster reference is unreachable twice over. Styles are inlined below rather than
    linked for the same reason.
--}}
<!DOCTYPE html>
<html lang="{{ $model['submission']['locale'] ?? 'en' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $model['form_title'] }}</title>
    <style>@include('pdf._styles')</style>
</head>
<body>
    <header class="head">
        <h1>{{ $model['form_title'] }}</h1>
        <table class="meta">
            <tr>
                <td class="meta__k">Respondent</td>
                <td class="meta__v">{{ $model['submission']['respondent'] }}</td>
                <td class="meta__k">Status</td>
                <td class="meta__v">{{ $model['submission']['status_label'] }}</td>
            </tr>
            <tr>
                <td class="meta__k">Submitted</td>
                <td class="meta__v">{{ $submittedAt }}</td>
                <td class="meta__k">Source</td>
                <td class="meta__v">{{ $model['submission']['source_label'] }}</td>
            </tr>
            <tr>
                <td class="meta__k">Reference</td>
                <td class="meta__v">{{ $model['submission']['id'] }}</td>
                <td class="meta__k">Version</td>
                <td class="meta__v">{{ $model['version_number'] ?? '—' }}</td>
            </tr>
        </table>
    </header>

    @foreach ($model['blocks'] as $block)
        <section class="block">
            @if ($block['label'] !== null)
                <h2>{{ $block['label'] }}</h2>
            @endif

            @if ($block['notice'] !== null)
                <p class="notice">{{ $block['notice'] }}</p>
            @endif

            @foreach ($block['fields'] as $field)
                @if ($field['role'] === 'prose')
                    {{-- A note: text the respondent read, with no answer slot. --}}
                    <p class="prose">{{ $field['label'] }}</p>
                @else
                    <table class="qa">
                        <tr>
                            <td class="qa__q">{{ $field['label'] }}</td>
                            <td class="qa__a">{{ $field['value'] }}</td>
                        </tr>
                    </table>
                @endif
            @endforeach
        </section>
    @endforeach

    <footer class="foot">
        {{-- Deliberately states what the document IS, so a reader knows an omitted question was
             never shown rather than lost. Without this line the PDF's central property — that it
             reproduces only the taken path — is invisible to the person holding it. --}}
        <p>This record shows only the questions this respondent was shown. Questions hidden by the
            form's own logic are omitted rather than left blank.</p>
        <p>Generated {{ $generatedAt }}.</p>
    </footer>
</body>
</html>
