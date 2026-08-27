<?php

declare(strict_types=1);

use App\Enums\AttachmentKind;
use App\Enums\FeedbackStatus;
use App\Enums\ScanStatus;
use App\Models\Attachment;
use App\Models\FeedbackReport;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment C3 — in-app feedback (PRD Feature #11). Extended in I7a.
|--------------------------------------------------------------------------
| Proves the submit path: any role may submit (can:feedback.submit is granted to all), the row is
| written under the tenant's RLS context (tenant_id filled by BelongsToTenant, user_id from the actor),
| remarks are required, and a report is strictly tenant-scoped (invisible from another tenant's context).
|
| I7a adds the screenshot arm and the tenant-side read surface. The screenshot cases are the interesting
| ones: the attachment has to exist BEFORE its owner row does (the FK runs report → attachment while the
| morph columns are NOT NULL), so the two ids agreeing is the thing worth asserting — a mismatch there
| would be silent, and would leave the console with an image it cannot find.
*/

/**
 * A tiny (~70-byte) real 1×1 PNG.
 *
 * `UploadedFile::fake()->image()` is NOT usable: it needs the GD extension, which this container does not
 * have, and the failure is a LogicException rather than a skip. Real bytes are better anyway — the service
 * CONTENT-SNIFFS the MIME rather than trusting the client header, so this fixture exercises the actual
 * gate. Same device as `brandingLogoFile()` in BrandingRoutesTest.
 */
function feedbackScreenshotFile(string $name = 'capture.png'): UploadedFile
{
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

    return UploadedFile::fake()->createWithContent($name, $png);
}

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('lets any role submit feedback, scoped to the tenant', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'viewer'); // the least-privileged role still holds feedback.submit

    $this->actingAs($user)
        ->post('http://acme.meridian.test/feedback', [
            'route' => '/dashboard',
            'remarks' => 'The dashboard is great, but exports are slow.',
            'browser_info' => ['userAgent' => 'PestUA', 'viewport' => '1440x900'],
        ])
        ->assertRedirect();

    enterTenant($tenant->id, $user->id);
    $report = FeedbackReport::query()->firstOrFail();
    expect($report->tenant_id)->toBe($tenant->id)
        ->and($report->user_id)->toBe($user->id)
        ->and($report->remarks)->toBe('The dashboard is great, but exports are slow.')
        ->and($report->status->value)->toBe('new')
        ->and($report->browser_info)->toMatchArray(['userAgent' => 'PestUA']);
});

it('requires remarks', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'viewer');

    $this->actingAs($user)
        ->post('http://acme.meridian.test/feedback', ['route' => '/dashboard'])
        ->assertSessionHasErrors('remarks');

    enterTenant($tenant->id, $user->id);
    expect(FeedbackReport::query()->count())->toBe(0);
});

it('isolates feedback to its own tenant under RLS', function (): void {
    $tenantA = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenantA->domains()->create(['domain' => 'acme']);
    $userA = User::factory()->create();
    enterTenant($tenantA->id, $userA->id);
    makeActiveMember($userA, 'viewer');

    $this->actingAs($userA)->post('http://acme.meridian.test/feedback', [
        'route' => '/dashboard',
        'remarks' => 'Tenant A confidential note.',
    ])->assertRedirect();

    // A second tenant's context sees none of tenant A's reports.
    $tenantB = Tenant::create(['name' => 'Globex', 'slug' => 'globex']);
    $userB = User::factory()->create();
    enterTenant($tenantB->id, $userB->id);
    makeActiveMember($userB, 'viewer');

    expect(FeedbackReport::query()->count())->toBe(0);

    // …while tenant A still sees its own.
    enterTenant($tenantA->id, $userA->id);
    expect(FeedbackReport::query()->count())->toBe(1);
});

it('stores a screenshot through the shared attachments pipeline and links it both ways', function (): void {
    Storage::fake('local');

    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'viewer');

    $this->actingAs($user)->post('http://acme.meridian.test/feedback', [
        'route' => '/forms/42',
        'remarks' => 'The map picker renders behind the toolbar.',
        'screenshot' => feedbackScreenshotFile(),
    ])->assertRedirect();

    enterTenant($tenant->id, $user->id);
    $report = FeedbackReport::query()->firstOrFail();
    $attachment = Attachment::query()->firstOrFail();

    expect($report->screenshot_attachment_id)->toBe($attachment->id)
        ->and($attachment->kind)->toBe(AttachmentKind::FeedbackScreenshot)
        // BOTH directions, because only one of them is enforced by anything. The FK guarantees the
        // report → attachment half; the morph columns have no DB constraint at all, so an id minted in
        // the wrong order would point at nothing and no error would ever be raised.
        ->and($attachment->attachable_type)->toBe('feedback_report')
        ->and($attachment->attachable_id)->toBe($report->id)
        // PII, unlike a brand logo: the image is a photograph of whatever was on the reporter's screen.
        ->and($attachment->is_pii)->toBeTrue()
        ->and($attachment->uploaded_by)->toBe($user->id)
        ->and($attachment->path)->toContain("tenants/{$tenant->id}/feedback_screenshot/");

    Storage::disk('local')->assertExists($attachment->path);
});

it('rejects a screenshot that is not a raster image', function (): void {
    Storage::fake('local');

    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'viewer');

    // An SVG is an XML document that can carry <script>, and this image is rendered back into the platform
    // operator's own console, same-origin on the central host — the highest-value stored-XSS target here.
    $this->actingAs($user)->post('http://acme.meridian.test/feedback', [
        'route' => '/dashboard',
        'remarks' => 'Trying to smuggle a script.',
        'screenshot' => UploadedFile::fake()->create('payload.svg', 4, 'image/svg+xml'),
    ])->assertSessionHasErrors('screenshot');

    enterTenant($tenant->id, $user->id);
    expect(FeedbackReport::query()->count())->toBe(0)
        ->and(Attachment::query()->count())->toBe(0);
});

it('submits without a screenshot, leaving the pointer null', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'viewer');

    $this->actingAs($user)->post('http://acme.meridian.test/feedback', [
        'route' => '/dashboard',
        'remarks' => 'No picture needed for this one.',
    ])->assertRedirect();

    enterTenant($tenant->id, $user->id);
    expect(FeedbackReport::query()->firstOrFail()->screenshot_attachment_id)->toBeNull()
        ->and(Attachment::query()->count())->toBe(0);
});

it('shows the workspace its own feedback to a holder of feedback.view, and hides it from everyone else', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);

    $viewer = User::factory()->create();
    enterTenant($tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');

    $this->actingAs($viewer)->post('http://acme.meridian.test/feedback', [
        'route' => '/dashboard',
        'remarks' => 'Exports are slow on big forms.',
    ])->assertRedirect();

    // `viewer` holds feedback.submit but NOT feedback.view — it may send, never read.
    $this->actingAs($viewer)->get('http://acme.meridian.test/feedback')->assertForbidden();

    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $this->withoutVite();
    $this->actingAs($owner)->get('http://acme.meridian.test/feedback')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('feedback/Index', false)
            ->has('data', 1)
            ->where('data.0.remarks', 'Exports are slow on big forms.')
            ->where('data.0.status', 'new')
            ->where('data.0.has_screenshot', false)
            ->where('meta.per_page', 25)
            ->where('empty_reason', null)
            ->has('filters.statuses', 4));
});

it('serves a screenshot to the workspace and 404s a report that has none', function (): void {
    Storage::fake('local');

    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $this->actingAs($owner)->post('http://acme.meridian.test/feedback', [
        'route' => '/dashboard',
        'remarks' => 'With a picture.',
        'screenshot' => feedbackScreenshotFile(),
    ])->assertRedirect();

    $this->actingAs($owner)->post('http://acme.meridian.test/feedback', [
        'route' => '/dashboard',
        'remarks' => 'Without a picture.',
    ])->assertRedirect();

    enterTenant($tenant->id, $owner->id);
    $withShot = FeedbackReport::query()->whereNotNull('screenshot_attachment_id')->firstOrFail();
    $withoutShot = FeedbackReport::query()->whereNull('screenshot_attachment_id')->firstOrFail();

    $this->actingAs($owner)
        ->get("http://acme.meridian.test/feedback/{$withShot->id}/screenshot")
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->actingAs($owner)
        ->get("http://acme.meridian.test/feedback/{$withoutShot->id}/screenshot")
        ->assertNotFound();
});

it('refuses a screenshot to a member who lacks feedback.view', function (): void {
    Storage::fake('local');

    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);
    $viewer = User::factory()->create();
    enterTenant($tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');

    // ONE CALLER for the whole case, which `viewer` makes possible: it holds feedback.submit, so it can
    // create the very report it is then refused. A second actor would resolve its permissions from this
    // request's state.
    $this->actingAs($viewer)->post('http://acme.meridian.test/feedback', [
        'route' => '/dashboard',
        'remarks' => 'A capture of my own screen.',
        'screenshot' => feedbackScreenshotFile(),
    ])->assertRedirect();

    enterTenant($tenant->id, $viewer->id);
    $report = FeedbackReport::query()->whereNotNull('screenshot_attachment_id')->firstOrFail();

    // ⚠️ THE REPORT IS IN THE VIEWER'S OWN TENANT, DELIBERATELY. `SubstituteBindings` runs BEFORE
    // `Authorize` (bootstrap/app.php), so a foreign id 404s at binding and never reaches the gate — which
    // is why the cross-tenant case below cannot stand in for this one. THIS is the assertion that pins
    // `can:feedback.view` on the screenshot route: it is a SEPARATE Route::get from the index, so
    // deleting or mistyping that one middleware call leaves the index's own refusal green.
    $this->actingAs($viewer)
        ->get("http://acme.meridian.test/feedback/{$report->id}/screenshot")
        ->assertForbidden();
});

it('404s another workspace screenshot at route-model binding, before the permission gate is consulted', function (): void {
    Storage::fake('local');

    $stranger = Tenant::create(['name' => 'Northwind', 'slug' => 'northwind']);
    $stranger->domains()->create(['domain' => 'northwind']);
    $strangerOwner = User::factory()->create();
    enterTenant($stranger->id, $strangerOwner->id);
    makeActiveMember($strangerOwner, 'owner');

    // Built with the factory under enterTenant() rather than by a second HTTP caller, so this case keeps
    // to one request as well. The screenshot is REAL: without it the 404 below would be the
    // "report has no screenshot" 404 from the controller, and the test would pass for the wrong reason.
    $foreignReport = FeedbackReport::create([
        'user_id' => $strangerOwner->id,
        'route' => '/forms',
        'remarks' => 'Another workspace has its own problems.',
        'status' => FeedbackStatus::New,
    ]);
    $foreign = Attachment::factory()->feedbackScreenshot($stranger->id, $foreignReport->id)->create();
    Storage::disk($foreign->disk)->put($foreign->path, 'FOREIGNBYTES');
    $foreignReport->forceFill(['screenshot_attachment_id' => $foreign->id])->save();

    $acme = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $acme->domains()->create(['domain' => 'acme']);
    $owner = User::factory()->create();
    enterTenant($acme->id, $owner->id);
    makeActiveMember($owner, 'owner');

    // ⚠️ THIS CASE IS NOT A SUBSTITUTE FOR THE ONE ABOVE AND MUST NOT BE READ AS ONE. It exercises the
    // tenant scope and RLS, not the permission gate — it passes unchanged with `can:feedback.view`
    // deleted from the route. An Owner is used precisely to make that explicit: the caller holds every
    // feedback permission there is, so the only thing refusing it is isolation.
    $this->actingAs($owner)
        ->get("http://acme.meridian.test/feedback/{$foreignReport->id}/screenshot")
        ->assertNotFound();
});

it('409s a feedback screenshot whose scan has not cleared, on the route that serves it', function (): void {
    // M34 — the second of the two `abort_unless(...->servable(), 409)` guards. FeedbackController.php:75
    // carries its own copy rather than routing through AttachmentController (the file says at :59-65 why),
    // so asserting one proves nothing about the other and both are pinned.
    //
    // The upload path leaves a real screenshot SERVABLE — Phase-1 stores it `skipped`, which is why the
    // test above gets a 200 on this same URI — so the quarantine state has to be set explicitly here.
    // That 200 is this test's POSITIVE CONTROL: same fixture, same caller, same route, one column apart.
    Storage::fake('local');

    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $this->actingAs($owner)->post('http://acme.meridian.test/feedback', [
        'route' => '/dashboard',
        'remarks' => 'With a picture that has not been scanned.',
        'screenshot' => feedbackScreenshotFile(),
    ])->assertRedirect();

    enterTenant($tenant->id, $owner->id);
    $report = FeedbackReport::query()->whereNotNull('screenshot_attachment_id')->firstOrFail();
    Attachment::query()->whereKey($report->screenshot_attachment_id)
        ->update(['virus_scan_status' => ScanStatus::Infected]);

    $this->actingAs($owner)
        ->get("http://acme.meridian.test/feedback/{$report->id}/screenshot")
        ->assertStatus(409);
});
