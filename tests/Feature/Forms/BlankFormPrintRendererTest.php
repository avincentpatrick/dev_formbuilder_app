<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Models\Form;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\BlankFormPrintRenderer;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\SubmissionPdfRenderer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// I12's typesetting half. Content assertions run against the HTML rather than the PDF byte stream,
// for the reason SubmissionPdfRendererTest records: PDF text is Flate-compressed, so grepping the
// bytes for `<script>` passes whether or not the escaping works — a vacuous green.

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->renderer = app(BlankFormPrintRenderer::class);
});

/**
 * A published form covering one field of every printed AREA, with ASCII-only authored text.
 *
 * ⚠️ THE ASCII-ONLY PART IS LOAD-BEARING, not tidiness. The WinAnsi case below asserts that the whole
 * rendered document survives a round trip through Windows-1252; that assertion only means "the
 * TEMPLATES contribute no unrepresentable glyph" while every authored string in the fixture is
 * itself representable. Put a CJK label in here and the test starts failing for the wrong reason.
 *
 * @return array{0: Form, 1: FormVersion}
 */
function printableForm(Tenant $tenant, User $user, string $title = 'Household Survey'): array
{
    $form = app(FormService::class)->create($tenant, $user, $title);
    $draft = $form->draftVersion;

    $section = FormSection::create([
        'form_version_id' => $draft->id,
        'key' => 'details',
        'label' => 'Details',
        'sequence' => 1,
        'created_by' => $user->id,
    ]);

    addFormField($draft, $user, 'full_name', FieldType::ShortText, 0, ['is_required' => RequiredMode::Required]);
    addFormField($draft, $user, 'visit_date', FieldType::Date, 1);
    addFormField($draft, $user, 'remarks', FieldType::LongText, 2);
    addFormField($draft, $user, 'color', FieldType::SingleSelect, 3, ['config' => ['options' => [
        ['value' => 'r', 'label' => 'Red'], ['value' => 'b', 'label' => 'Blue'],
    ]]]);
    addFormField($draft, $user, 'consent', FieldType::Note, 4, ['label' => 'Please read this aloud.']);
    addFormField($draft, $user, 'where', FieldType::Geopoint, 5, ['form_section_id' => $section->id]);
    addFormField($draft, $user, 'sign_here', FieldType::Signature, 6, ['form_section_id' => $section->id]);
    addFormField($draft, $user, 'internal_ref', FieldType::Hidden, 7);

    $published = app(PublishService::class)->publish($form->refresh(), $user);

    return [$form->refresh(), $published];
}

it('emits a real PDF', function (): void {
    [$form, $version] = printableForm($this->tenant, $this->user);

    expect($this->renderer->render($form, $version))->toStartWith('%PDF-');
});

it('reuses the submission PDF engine settings rather than declaring its own', function (): void {
    // Doc #26 §5 pinned isRemoteEnabled/isPhpEnabled/isJavascriptEnabled false and a chroot before
    // the engine was chosen. Two Options objects would agree the day they were written and drift the
    // first time one was tightened, so this asserts the shared one covers this surface too — in
    // particular that the chroot still contains the directory these templates live in.
    $options = SubmissionPdfRenderer::dompdfOptions();

    expect($options->getIsRemoteEnabled())->toBeFalse()
        ->and($options->getIsPhpEnabled())->toBeFalse()
        ->and($options->getIsJavascriptEnabled())->toBeFalse()
        ->and($options->getChroot())->toContain(realpath(resource_path('views/pdf')));
});

it('escapes every tenant-authored string on the page, not only some of them', function (): void {
    // §5 decision (3) binds the WHOLE surface. The named failure mode is escaping one authored
    // string while leaving another raw beside it in the same document — so this plants the payload
    // in five structurally different places at once.
    $payload = '<script>alert(1)</script>';

    $form = app(FormService::class)->create($this->tenant, $this->user, $payload);
    $draft = $form->draftVersion;

    $section = FormSection::create([
        'form_version_id' => $draft->id,
        'key' => 'sect',
        'label' => $payload,
        'sequence' => 1,
        'created_by' => $this->user->id,
    ]);

    addFormField($draft, $this->user, 'q1', FieldType::ShortText, 0, [
        'label' => $payload,
        'hint' => $payload,
        'form_section_id' => $section->id,
    ]);
    addFormField($draft, $this->user, 'q2', FieldType::SingleSelect, 1, [
        'form_section_id' => $section->id,
        'config' => ['options' => [['value' => 'a', 'label' => $payload]]],
    ]);

    $published = app(PublishService::class)->publish($form->refresh(), $this->user);
    $html = $this->renderer->html($form->refresh(), $published);

    // Form title (x2 — the running head repeats it), section heading, field label, hint, option
    // label. Nothing anywhere renders it live.
    expect(substr_count($html, '&lt;script&gt;alert(1)&lt;/script&gt;'))->toBeGreaterThanOrEqual(6)
        ->and($html)->not->toContain('<script>alert(1)</script>');
});

it('escapes an attribute-breaking payload, not only an element-breaking one', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->user, '" onload="alert(1)');
    addFormField($form->draftVersion, $this->user, 'q1', FieldType::ShortText, 0);
    $published = app(PublishService::class)->publish($form->refresh(), $this->user);

    // The title reaches a <title> element and the running head; neither may break out.
    expect($this->renderer->html($form->refresh(), $published))
        ->toContain('&quot; onload=&quot;alert(1)')
        ->not->toContain('" onload="alert(1)"');
});

it('renders nothing dompdf\'s WinAnsi core fonts cannot draw', function (): void {
    // ⚠️ THE ASSERTION THAT KEEPS THE BOXES ON THE PAGE.
    //
    // dompdf's built-in fonts are the PDF core fonts (Helvetica / Times / Courier), which are
    // WinAnsi-encoded. U+2610 BALLOT BOX and its siblings are NOT in that repertoire: dompdf drops
    // or mangles them SILENTLY, with no error and no warning, so a checkbox typed as a glyph rather
    // than drawn as a bordered element ships as a visual defect nobody sees until they open a PDF.
    //
    // Windows-1252 IS the WinAnsi repertoire, so a lossless round trip through it is exactly the
    // question. `mb_convert_encoding` substitutes an unrepresentable codepoint, so the strings
    // differ precisely when something unprintable is present. The fixture is ASCII-only (see
    // printableForm's docblock), so anything this catches came from a template.
    //
    // It is checked on the RENDERED output, never the template source: Blade comments are stripped
    // before rendering, and those comments legitimately carry box-drawing characters in this
    // codebase's house style. Scanning the source would fail on a comment and pass on an HTML
    // entity — exactly backwards.
    [$form, $version] = printableForm($this->tenant, $this->user);
    $html = $this->renderer->html($form, $version);

    $roundTripped = mb_convert_encoding(
        (string) mb_convert_encoding($html, 'Windows-1252', 'UTF-8'),
        'UTF-8',
        'Windows-1252',
    );

    expect($roundTripped)->toBe($html);
});

it('draws every box with a border instead of typing it as a character', function (): void {
    // The positive half of the case above: proving no bad glyph is present says nothing about
    // whether the boxes exist at all. A comb cell, a choice box and a grid cell are each an empty
    // bordered element, and the comb table must keep `border-collapse: separate` — collapsing it
    // merges the cell walls into one ruled line and the comb silently stops being a comb.
    [$form, $version] = printableForm($this->tenant, $this->user);
    $html = $this->renderer->html($form, $version);

    expect($html)->toContain('class="comb"')
        ->and($html)->toContain('class="choice__box"')
        ->and($html)->toContain('class="ruled"')
        ->and($html)->toContain('class="sign"')
        ->and($html)->toContain('border-collapse: separate')
        // The date's captioned groups, which are what make a handwritten date unambiguous.
        ->and($html)->toContain('>DD<')
        ->and($html)->toContain('>MM<')
        ->and($html)->toContain('>YYYY<');
});

it('prints the field key beside each answer area', function (): void {
    // Decided with the user 2026-08-09: the OCR stage maps a scanned region back to a field by this
    // stamp, so it must survive a page being scanned out of order.
    [$form, $version] = printableForm($this->tenant, $this->user);
    $html = $this->renderer->html($form, $version);

    expect($html)->toContain('>full_name<')
        ->and($html)->toContain('>visit_date<')
        // ...and the Omitted field contributes no key, because it contributes no area.
        ->and($html)->not->toContain('>internal_ref<');
});

it('states on the paper when scans of it cannot be read automatically', function (): void {
    // The footer is derived per version from its own frozen bytes, never from forms.capability_flags
    // (a cache describing only the CURRENTLY published version). A geopoint makes this one ineligible.
    [$form, $version] = printableForm($this->tenant, $this->user);

    expect($this->renderer->html($form, $version))->toContain('cannot be read automatically');
});

it('survives a form far taller than one page, grids included', function (): void {
    // Two things are being probed, and neither is visible in the render model.
    //
    // `.q` carries `page-break-inside: avoid`, which is right for a short question and a real
    // question mark for a 40-row grid: an unbreakable box taller than the page has nowhere to go.
    // And `.runhead` is `position: fixed`, which only earns its place if there is a second page for
    // it to repeat onto.
    //
    // A model-level assertion cannot reach either; this drives the whole engine and asserts the one
    // thing that is checkable without a PDF parser — that a document this shape is produced at all,
    // and is bigger than the single-page case.
    $form = app(FormService::class)->create($this->tenant, $this->user, 'Long Survey');
    $draft = $form->draftVersion;

    $rows = [];
    for ($i = 0; $i < 40; $i++) {
        $rows[] = ['value' => "r{$i}", 'label' => "Row {$i}"];
    }

    addFormField($draft, $this->user, 'big_grid', FieldType::LikertMatrix, 0, ['config' => [
        'rows' => $rows,
        'columns' => [
            ['value' => '1', 'label' => 'Low'],
            ['value' => '2', 'label' => 'Mid'],
            ['value' => '3', 'label' => 'High'],
        ],
    ]]);

    for ($i = 0; $i < 30; $i++) {
        addFormField($draft, $this->user, "q{$i}", FieldType::ShortText, $i + 1);
    }

    $published = app(PublishService::class)->publish($form->refresh(), $this->user);
    $bytes = $this->renderer->render($form->refresh(), $published);

    [$small, $smallVersion] = printableForm($this->tenant, $this->user, 'Small');
    $smallBytes = $this->renderer->render($small, $smallVersion);

    expect($bytes)->toStartWith('%PDF-')
        ->and(strlen($bytes))->toBeGreaterThan(strlen($smallBytes));
});

it('slugs the download filename, and falls back rather than emitting a bare version', function (): void {
    // The title is tenant-authored free text and reaches a Content-Disposition header. Slugging
    // leaves nothing to inject with; the fallback covers a title that slugs to the empty string.
    [$form, $version] = printableForm($this->tenant, $this->user, 'Household Survey: 2026 "Round 2"');
    expect($this->renderer->filename($form, $version))->toBe('household-survey-2026-round-2-v1-blank.pdf');

    [$blank, $blankVersion] = printableForm($this->tenant, $this->user, '???');
    expect($this->renderer->filename($blank, $blankVersion))->toBe('blank-form-v1-blank.pdf');
});
