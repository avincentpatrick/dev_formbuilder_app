<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\FormSection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\SubmissionPdfRenderer;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

// H17 discharges `docs/testing-strategy.md` §3's LAST-BUT-TWO owed output-encoding row: "the
// queued-PDF half of that row (H17)". `docs/piping-output-encoding-design.md` §5 names the surface,
// the escaper (`htmlspecialchars(ENT_QUOTES, 'UTF-8')`), the owning increment and the required test
// ("markup answer renders as visible text in the PDF") — all four were written before the engine
// existed, and this file is the test that row demands.
//
// The assertions run against the HTML handed to dompdf, not against the PDF byte stream. That is
// deliberate and is the difference between a real test and a vacuous one: PDF text is Flate-
// compressed, so grepping the output for `<script>` would pass whether or not the escaping worked.
// H6b's mutation pass caught exactly that shape of green test, and the lesson is recorded.

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

const XSS = '<script>alert(1)</script>';

/** A published form whose TITLE, SECTION LABEL and one field label all carry markup. */
function xssForm(Tenant $tenant, User $owner): Form
{
    // §5 decision (3): the contract binds EVERY untrusted value on the surface, not only piped
    // ones. The named failure mode is escaping the answer while leaving the form title raw beside
    // it in the same document — so the title is markup here too.
    $form = app(FormService::class)->create($tenant, $owner, 'Report '.XSS);
    $version = $form->draftVersion;

    $section = FormSection::create([
        'form_version_id' => $version->id,
        'key' => 'details',
        'label' => 'Details '.XSS,
        'sequence' => 1,
    ]);
    addFormField($version, $owner, 'comment', FieldType::LongText, 1, [
        'form_section_id' => $section->id,
        'label' => 'Comment '.XSS,
    ]);
    // A label that pipes the respondent's own answer — the piped path, which is the one §5 was
    // written for. The hole is filled with markup at render time, downstream of any authoring gate.
    addFormField($version, $owner, 'echo', FieldType::ShortText, 2, [
        'form_section_id' => $section->id,
        'label' => 'You said: ${comment}',
    ]);

    app(PublishService::class)->publish($form->refresh(), $owner);

    return $form->refresh();
}

it('renders a markup ANSWER as visible text, never as live markup', function (): void {
    // The literal test §5's table row demands.
    $form = xssForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, [
        'comment' => XSS,
        'echo' => 'ok',
    ]);

    $html = app(SubmissionPdfRenderer::class)->html($submission);

    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->not->toContain('<script>');
});

it('escapes the form title, the section heading and the field label too', function (): void {
    // Decision (3). An escaper cannot tell where a string came from, so all four sites are bound —
    // and the title/heading are AUTHOR-supplied rather than respondent-supplied, which is exactly
    // the distinction §5 says must NOT be used to decide whether to escape.
    $form = xssForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['comment' => 'plain', 'echo' => 'ok']);

    $html = app(SubmissionPdfRenderer::class)->html($submission);

    expect(substr_count($html, '&lt;script&gt;'))->toBeGreaterThanOrEqual(3)
        ->and($html)->toContain('Report &lt;script&gt;')
        ->and($html)->toContain('Details &lt;script&gt;')
        ->and($html)->toContain('Comment &lt;script&gt;')
        ->and($html)->not->toContain('<script>');
});

it('escapes markup that arrives through a PIPED hole', function (): void {
    // The subtlest of the four sites and the reason §5 exists: `echo`'s label is authored as
    // `You said: ${comment}`, so the markup is injected into the LABEL at render time by the
    // template engine — after every authoring-time gate has already passed the label as safe.
    $form = xssForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, [
        'comment' => XSS,
        'echo' => 'ok',
    ]);

    $html = app(SubmissionPdfRenderer::class)->html($submission);

    expect($html)->toContain('You said: &lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->not->toContain('You said: <script>');
});

it('escapes an attribute-breaking payload, not only an element-breaking one', function (): void {
    // ENT_QUOTES is the half of the escaper spec that a `htmlspecialchars($v)` default would miss:
    // without it, double quotes pass through and a value interpolated into an attribute escapes its
    // own attribute. Nothing in this template interpolates into an attribute today, but §5 names
    // "HTML text + attribute" as the context, so the escaper's configuration is what is pinned.
    $form = xssForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, [
        'comment' => '" onload="alert(1)',
        'echo' => "' onerror='alert(1)",
    ]);

    $html = app(SubmissionPdfRenderer::class)->html($submission);

    expect($html)->toContain('&quot; onload=&quot;alert(1)')
        ->and($html)->toContain('&#039; onerror=&#039;alert(1)');
});

it('contains no raw-output Blade directive in any PDF template', function (): void {
    // A structural guard rather than a behavioural one, and it earns its place: every assertion
    // above tests the values this fixture happens to render. A `{!! !!}` added to a template for a
    // value no current test exercises would slip past all of them. Doc #26 records that zero `{!!`
    // exists in application code; this keeps that true for the one surface that would suffer most.
    $templates = glob(resource_path('views/pdf/*.blade.php')) ?: [];

    // Anti-vacuity, and this guard needed it: written first as
    // `->not->toContain('{!!', basename($t).' must not use raw output')`, which reads like an
    // assertion with a failure message but is not — Pest's `toContain` takes VARARGS NEEDLES, so
    // the message became a second needle and `not->toContain(a, b)` passes whenever EITHER is
    // absent. The message string never appears in a template, so the whole assertion was green by
    // construction. The mutation pass caught it (raw-outputting a value left this test passing).
    expect($templates)->not->toBeEmpty('no PDF templates found to scan');

    foreach ($templates as $template) {
        expect(file_get_contents($template))->not->toContain('{!!');
    }
});

it('pins the dompdf options Doc #26 committed to before the engine was chosen', function (): void {
    // Asked of the Options object through dompdf's own getters, not grepped out of the source — a
    // source-text assertion would survive dompdf renaming a setter or changing a default.
    //
    // These two flags are not hygiene. Of the 20 published advisories against dompdf/dompdf,
    // essentially every one is an SVG parse, a remote font fetch, a remote file inclusion or an
    // image-handling path, and all of them need remote loading on.
    $options = SubmissionPdfRenderer::dompdfOptions();

    expect($options->getIsRemoteEnabled())->toBeFalse()
        ->and($options->getIsPhpEnabled())->toBeFalse()
        ->and($options->getIsJavascriptEnabled())->toBeFalse()
        ->and($options->getChroot())->toBe([resource_path('views/pdf')]);
});

it('emits a real PDF, and one that survives a markup-bearing document', function (): void {
    // The end-to-end shape check. Cheap, but it is the only thing that would catch a template that
    // renders fine as HTML and makes dompdf throw — an unclosed tag, a CSS property its 2.1 parser
    // rejects, a font it cannot resolve.
    $form = xssForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['comment' => XSS, 'echo' => 'ok']);

    $bytes = app(SubmissionPdfRenderer::class)->render($submission);

    expect($bytes)->toStartWith('%PDF-')
        ->and(strlen($bytes))->toBeGreaterThan(1000);
});

it('renders a submission with no answers at all without throwing', function (): void {
    // §3.4's "render must never throw" applied to this surface. A PDF is generated on a queue
    // worker, where a 500 is a dead-lettered job and a staff user who never learns why — so the
    // degenerate inputs are worth a test rather than an assumption.
    //
    // Note which degenerate case this is NOT: a submission with no form version is unreachable,
    // because `submissions.form_version_id` is NOT NULL. The presenter's `$version === null` guard
    // is defensive against an unloaded relation (the same guard the inbox carries), not a state
    // the database can hold. Tried it, got a not-null violation, wrote down the reason.
    $form = xssForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, []);

    $bytes = app(SubmissionPdfRenderer::class)->render($submission);
    $html = app(SubmissionPdfRenderer::class)->html($submission);

    expect($bytes)->toStartWith('%PDF-')
        // Every question still appears — an unanswered form was still SHOWN to someone.
        ->and($html)->toContain('Comment &lt;script&gt;')
        ->and($html)->toContain('—');
});

it('renders a form with nothing visible in it without emitting an empty block', function (): void {
    // The other reachable degenerate: a version whose every field is Omitted. The document should
    // be a header and a footer, not a section heading with nothing under it.
    $form = app(FormService::class)->create($this->tenant, $this->owner, 'Machine only');
    $version = $form->draftVersion;
    addFormField($version, $this->owner, 'age', FieldType::Integer, 1);
    addFormField($version, $this->owner, 'ref', FieldType::Hidden, 2);
    app(PublishService::class)->publish($form->refresh(), $this->owner);

    $submission = seedInboxSubmission($form->refresh(), $this->owner, SubmissionStatus::Submitted, ['ref' => 'X-1']);

    $html = app(SubmissionPdfRenderer::class)->html($submission);

    expect(app(SubmissionPdfRenderer::class)->render($submission))->toStartWith('%PDF-')
        ->and($html)->not->toContain('X-1');
});
