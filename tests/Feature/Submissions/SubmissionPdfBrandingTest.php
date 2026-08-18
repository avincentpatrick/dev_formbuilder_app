<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\PlanTier;
use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Branding\TenantBrandingService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\SubmissionPdfRenderer;
use App\Support\Branding\BrandPalette;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment H23a4 — tenant branding on the submission PDF (ADR-0014 §D8).
|--------------------------------------------------------------------------
| COLOUR ONLY. §D8 stores the ramp partly because dompdf implements CSS 2.1 and can resolve neither a
| custom property nor `oklch()`, so the document needs literal hexes; a LOGO would additionally have
| needed `ext-gd`, which is absent from the app container and from all four CI jobs, so it would render
| on a developer's machine and throw in the pipeline. That is asserted here rather than merely written
| down, because "no images" is the kind of contract a later increment breaks by accident.
|
| Every assertion runs against `SubmissionPdfRenderer::html()`, never the PDF bytes — the file's own
| standing doctrine: PDF text is Flate-compressed, so a hex would be absent from the byte stream whether
| or not the branding worked.
*/

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

function brandedPdfForm(Tenant $tenant, User $owner): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Field report');
    addFormField($form->draftVersion, $owner, 'comment', FieldType::LongText, 1, ['label' => 'Comment']);
    app(PublishService::class)->publish($form->refresh(), $owner);

    return $form->refresh();
}

function renderPdfHtml(Tenant $tenant, User $owner): string
{
    $form = brandedPdfForm($tenant, $owner);
    $submission = seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['comment' => 'a plain answer']);

    return app(SubmissionPdfRenderer::class)->html($submission);
}

it('paints the header rule, the title and the prose panel in the tenant fill', function (): void {
    assignPlanTier(PlanTier::Starter);
    $ramp = app(TenantBrandingService::class)->setBrandColor($this->tenant, '#C0392B');

    $html = renderPdfHtml($this->tenant, $this->owner);

    // The LIGHT theme's tokens, and only the light theme: paper is white.
    expect($html)->toContain($ramp->token('light', 'bg'))
        ->and($html)->toContain($ramp->token('light', 'tint'))
        // The greys the branded declarations replaced must be gone, or the assertion above could be
        // satisfied by a stylesheet that emitted both.
        ->and($html)->not->toContain('#f4f4f4')
        ->and($html)->not->toContain('1.5pt solid #1a1a1a');
});

it('leaves the neutrals alone whatever colour the tenant picked', function (): void {
    assignPlanTier(PlanTier::Starter);
    app(TenantBrandingService::class)->setBrandColor($this->tenant, '#C0392B');

    $html = renderPdfHtml($this->tenant, $this->owner);

    // ADR-0014 §D7 on paper: a respondent's answers read the same whatever the tenant chose. Body ink,
    // the metadata key colour, the question colour and the section hairline are all still achromatic.
    expect($html)->toContain('color: #1a1a1a')
        ->and($html)->toContain('#5a5a5a')
        ->and($html)->toContain('#3a3a3a')
        ->and($html)->toContain('0.5pt solid #c8c8c8');
});

it('renders the product default for a tenant that set no brand colour', function (): void {
    $html = renderPdfHtml($this->tenant, $this->owner);

    expect($html)->toContain(BrandPalette::PRODUCT['bg'])
        ->and($html)->toContain(BrandPalette::PRODUCT['tint']);
});

it('renders the product default for a stored ramp on a plan without branding', function (): void {
    // The stored/active distinction, on the surface where getting it wrong is least visible: a
    // downgraded tenant would otherwise keep receiving branded PDFs forever, since nothing about a
    // queued render is ever looked at by a human before it is filed.
    assignPlanTier(PlanTier::Starter);
    $ramp = app(TenantBrandingService::class)->setBrandColor($this->tenant, '#C0392B');

    enterTenant($this->tenant->id, $this->owner->id);
    assignPlanTier(PlanTier::Free);

    $html = renderPdfHtml($this->tenant->refresh(), $this->owner);

    expect($html)->not->toContain($ramp->token('light', 'bg'))
        ->and($html)->toContain(BrandPalette::PRODUCT['bg']);
});

it('adds no image, no remote reference and no logo to the document', function (): void {
    assignPlanTier(PlanTier::Starter);
    app(TenantBrandingService::class)->setBrandColor($this->tenant, '#C0392B');

    // Give the tenant a logo it could have used, so this asserts a DECISION rather than the absence of
    // an opportunity. dompdf's PNG and WebP paths both throw without ext-gd, which is absent here and in
    // every CI job; the whole no-external-references contract (`isRemoteEnabled = false`, chroot pinned
    // to the view directory) rests on this staying true.
    $logo = brandingLogoAttachment($this->tenant->id);
    $this->tenant->forceFill(['logo_attachment_id' => $logo->id])->save();

    $html = renderPdfHtml($this->tenant->refresh(), $this->owner);

    expect($html)->not->toContain('<img')
        ->and($html)->not->toContain('url(')
        ->and($html)->not->toContain('@font-face')
        ->and($html)->not->toContain('branding/logo')
        ->and($html)->not->toContain('data:image');
});

it('is asserted on the HTML because the hex is unfindable in the compressed PDF', function (): void {
    // The anti-vacuity note the sibling file makes about `<script>`, made once here about a hex — so a
    // later reader does not "strengthen" these tests by pointing them at render() and get a green that
    // means nothing.
    assignPlanTier(PlanTier::Starter);
    $ramp = app(TenantBrandingService::class)->setBrandColor($this->tenant, '#C0392B');

    $form = brandedPdfForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['comment' => 'a']);

    $pdf = app(SubmissionPdfRenderer::class)->render($submission);

    expect($pdf)->toStartWith('%PDF-')
        ->and($pdf)->not->toContain($ramp->token('light', 'bg'));
});
