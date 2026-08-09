<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment I12 — GET /forms/{form}/versions/{version}/print
|--------------------------------------------------------------------------
| The delivery surface for the printable blank form. Four properties carry the weight:
|
|   1. A DRAFT IS REFUSED. Not a policy preference: a draft's `schema_snapshot` is literally `[]`, so
|      without the guard the request would 200 with a titled document containing no questions —
|      failing as "an empty form" rather than as "a refused one". ADR-0013 is the other half: paper
|      printed against a shape that can still change has nothing on it to say so.
|   2. A SUPERSEDED VERSION PRINTS, and that is the case the OCR chain needs — a stack printed from
|      v1 must stay reprintable after v2 lands, or the scans cannot be re-read against their layout.
|   3. IT IS UNGATED BY PLAN. The obvious-looking `feature:ocr_single` would be wrong: that key is
|      Professional+, and printing a blank form is useful with no OCR anywhere in the picture.
|   4. THE VERSION IS SCOPE-BOUND TO THE FORM, so another form's version id cannot be printed under
|      this form's authorization check.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function printUrl(Form $form, FormVersion $version): string
{
    return "http://acme.meridian.test/forms/{$form->id}/versions/{$version->id}/print";
}

/** A published, printable form owned by the acting user. */
function printableRouteForm(User $owner, string $title = 'Clinic Intake'): Form
{
    /** @var Tenant $tenant */
    $tenant = Tenant::query()->where('slug', 'acme')->firstOrFail();

    $form = app(FormService::class)->create($tenant, $owner, $title);
    addFormField($form->draftVersion, $owner, 'full_name', FieldType::ShortText, 0, [
        'is_required' => RequiredMode::Required,
    ]);
    app(PublishService::class)->publish($form->refresh(), $owner);

    return $form->refresh();
}

it('downloads a published version as a PDF attachment', function (): void {
    $form = printableRouteForm($this->admin);
    $version = FormVersion::query()->whereKey($form->current_published_version_id)->firstOrFail();

    $response = $this->actingAs($this->admin)->get(printUrl($form, $version));

    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('content-disposition', 'attachment; filename=clinic-intake-v1-blank.pdf');

    // The bytes are a real document, not an error page rendered with the right content type.
    expect($response->getContent())->toStartWith('%PDF-');
});

it('refuses a DRAFT version, whose snapshot is empty by construction', function (): void {
    // ⚠️ The guard this route exists behind. Delete `abort_if` and this case is the only thing that
    // reddens — every other case in the file still passes, because they all print a published row.
    $form = printableRouteForm($this->admin);

    $this->actingAs($this->admin)
        ->get(printUrl($form, $form->refresh()->draftVersion))
        ->assertNotFound();
});

it('still prints a SUPERSEDED version after a newer one is published', function (): void {
    // The OCR chain depends on this: paper already in the field was printed against v1's layout, and
    // re-reading those scans means reprinting v1, not v2.
    $form = printableRouteForm($this->admin);
    $v1 = FormVersion::query()->whereKey($form->current_published_version_id)->firstOrFail();

    addFormField($form->refresh()->draftVersion, $this->admin, 'phone', FieldType::Phone, 1);
    app(PublishService::class)->publish($form->refresh(), $this->admin);

    enterTenant($this->tenant->id, $this->admin->id);
    expect($v1->refresh()->status->value)->toBe('superseded');

    $this->actingAs($this->admin)->get(printUrl($form->refresh(), $v1))->assertOk();
});

it('404s a version belonging to a different form', function (): void {
    // `->scopeBindings()` is what makes this a binding failure rather than a successful print of
    // somebody else's schema under this form's authorization check.
    $mine = printableRouteForm($this->admin, 'Mine');
    $other = printableRouteForm($this->admin, 'Other');
    $otherVersion = FormVersion::query()->whereKey($other->current_published_version_id)->firstOrFail();

    $this->actingAs($this->admin)->get(printUrl($mine, $otherVersion))->assertNotFound();
});

it('refuses a member with no read access to the form', function (): void {
    // The gate is `can:view,form` — FormPolicy composition, not a bare authenticated check.
    $form = printableRouteForm($this->admin);
    $version = FormVersion::query()->whereKey($form->current_published_version_id)->firstOrFail();

    $stranger = User::factory()->create();
    enterTenant($this->tenant->id, $stranger->id);
    makeActiveMember($stranger, 'viewer');

    $this->actingAs($stranger)->get(printUrl($form, $version))->assertForbidden();
});

it('rejects a guest outright', function (): void {
    $form = printableRouteForm($this->admin);
    $version = FormVersion::query()->whereKey($form->current_published_version_id)->firstOrFail();

    $this->get(printUrl($form, $version))->assertRedirect();
});

it('writes no audit row and meters no usage', function (): void {
    // Deliberate, and the same posture as the XLSForm export directly above it in routes/tenant.php:
    // deriving a different rendering of rows the caller may already read is not a new disclosure.
    // Contrast the submission PDF, which audits and meters because it STORES an artifact against the
    // tenant's quota. Pinned so a later increment adds one on purpose rather than by reflex.
    $form = printableRouteForm($this->admin);
    $version = FormVersion::query()->whereKey($form->current_published_version_id)->firstOrFail();

    enterTenant($this->tenant->id, $this->admin->id);
    $before = DB::table('audits')->count();

    $this->actingAs($this->admin)->get(printUrl($form, $version))->assertOk();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(DB::table('audits')->count())->toBe($before);
});
