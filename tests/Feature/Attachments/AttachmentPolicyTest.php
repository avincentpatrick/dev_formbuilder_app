<?php

declare(strict_types=1);

use App\Enums\FeedbackStatus;
use App\Enums\ResourceCapacity;
use App\Enums\ScanStatus;
use App\Enums\SubmissionStatus;
use App\Models\Attachment;
use App\Models\FeedbackReport;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increments M29 and M33 — the HTTP coverage `GET /attachments/{attachment}` did not have.
|--------------------------------------------------------------------------
| `AttachmentPolicy::view()` is the only role gate on the authenticated media read path, and until M29
| nothing exercised it: `AttachmentRlsTest` asserts the DATABASE isolation four ways, and every other
| `attachments` mention under `tests/` is `TenantUrl` string-building. So the one predicate deciding who may
| read a tenant's stored bytes had no test of any kind.
|
| M29 pinned the KIND. G6 wrote the policy as a flat `submissions.view` check that never read its
| `$attachment` argument, which was true of every kind G6 could produce. ADR-0015 §D6 then filed a feedback
| screenshot into the same shared table — a PII image (§D8) whose own route is gated `feedback.view`,
| Owner and Admin only — so the id-addressed sibling route served exactly the roles the dedicated route
| refuses.
|
| M33 pins the SCOPE, which M29 explicitly left open (ADR-0015 §D10). `SubmissionPolicy::view()` requires
| `submissions.view` AND org-wide visibility or per-form collaboration or being the respondent; this policy
| required only the permission. `form_editor` and `reviewer` — the two roles holding `submissions.view`
| without `dashboard.org.view` — could therefore read every stored object in the tenant by id, including
| forms they had never collaborated on and forms they had been REMOVED from.
|
| ⚠️ EVERY CASE HERE USES ONE CALLER AND MAKES ONE REQUEST. A second actor in one test resolves its
| permissions from the first request's state, and any tenant-scoped model write after a request needs
| `enterTenant()` re-called because the request tears the RLS GUC down. All fixture writes therefore happen
| BEFORE the single `actingAs`, never between two.
|
| ⚠️ THE FIXTURES OWN REAL ROWS, AND THAT IS LOAD-BEARING SINCE M33. `Attachment::factory()` defaults to a
| `form_field` alias with a RANDOM uuid; the scoped arms resolve the owner and fail closed when it does not
| exist, so a fixture left on the default would 403 for every role and prove nothing about permissions.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Storage::fake(config('filesystems.default'));
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/**
 * A tenant, one member at `$roleName`, and a feedback screenshot or a piece of respondent media whose
 * bytes exist on the fake disk. Used by the M29 kind cases, where the owner's scope is not what is under
 * test — so the media case still builds a real published form and a real submission to own it.
 *
 * @param  'feedback_screenshot'|'submission_media'  $kind
 * @return array{0: User, 1: Attachment}
 */
function memberWithStoredObject(string $roleName, string $kind): array
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);

    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, $roleName);

    if ($kind === 'feedback_screenshot') {
        $report = FeedbackReport::create([
            'user_id' => $user->id,
            'route' => '/dashboard',
            'remarks' => 'A capture of my own screen.',
            'status' => FeedbackStatus::New,
        ]);
        $attachment = Attachment::factory()->feedbackScreenshot($tenant->id, $report->id)->create();
    } else {
        // A real form and a real submission, owned by this same member so the scope arm is satisfied and
        // the KIND is the only variable. (M33: the old fixture used the factory default — a form_field
        // alias with a random uuid — which the scoped arm now correctly refuses.)
        $form = publishedInboxForm($tenant, $user);
        $submission = seedInboxSubmission($form, $user, SubmissionStatus::Submitted, ['full_name' => 'X']);
        $attachment = Attachment::factory()->forSubmission($submission)->clean()->create();
    }

    Storage::disk($attachment->disk)->put($attachment->path, 'STOREDBYTES');

    return [$user, $attachment];
}

/**
 * The M33 scope fixture: a published form owned by someone else, one submitted row on it, a stored object
 * of `$kind`, and a caller at `$roleName` who does or does not collaborate on that form.
 *
 * The submission's respondent is deliberately NULL — otherwise `SubmissionPolicy`'s respondent arm could
 * satisfy a case meant to be decided by collaboration, and the test would pass for the wrong reason.
 *
 * @param  'submission_media'|'export_artifact'|'webhook_envelope'|'branding_logo'|'staged_field'  $kind
 * @return array{0: User, 1: Attachment, 2: Form}
 */
function callerWithScopedObject(string $roleName, bool $collaborates, string $kind = 'submission_media'): array
{
    $tenant = inboxTenant();

    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = publishedInboxForm($tenant, $owner);
    $submission = seedInboxSubmission($form, null, SubmissionStatus::Submitted, ['full_name' => 'X']);

    $attachment = match ($kind) {
        'export_artifact' => Attachment::factory()->exportArtifact($submission)->create(),
        'webhook_envelope' => Attachment::factory()->webhookEnvelope()->create(),
        'branding_logo' => Attachment::factory()->brandingLogo($tenant->id)->create(),
        'staged_field' => Attachment::factory()->forField(
            FormField::query()->where('form_version_id', $form->current_published_version_id)->firstOrFail(),
        )->clean()->create(),
        default => Attachment::factory()->forSubmission($submission)->clean()->create(),
    };

    Storage::disk($attachment->disk)->put($attachment->path, 'STOREDBYTES');

    $caller = User::factory()->create();
    enterTenant($tenant->id, $caller->id);
    makeActiveMember($caller, $roleName);

    if ($collaborates) {
        makeCollaborator(
            $form,
            $caller,
            $roleName === 'reviewer' ? ResourceCapacity::Reviewer : ResourceCapacity::Editor,
        );
    }

    return [$caller, $attachment, $form];
}

/** The URL under test. Hard-coded rather than `route()`, matching the sibling suites on this host. */
function attachmentUrl(Attachment $attachment): string
{
    return "http://acme.meridian.test/attachments/{$attachment->id}";
}

/*
|--------------------------------------------------------------------------
| M29 — the kind reaches the gate.
|--------------------------------------------------------------------------
*/

it('serves respondent media to a viewer, which is the permission this route was written for', function (): void {
    // THE POSITIVE CONTROL, and it is named rather than assumed. Without it, a policy that refused
    // EVERYTHING would satisfy every other case in this file. `viewer` holds `submissions.view` AND
    // `dashboard.org.view`, so it clears both the permission floor and the M33 scope.
    [$viewer, $attachment] = memberWithStoredObject('viewer', 'submission_media');

    $response = $this->actingAs($viewer)->get(attachmentUrl($attachment));

    $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->streamedContent())->toBe('STOREDBYTES');
});

it('refuses a feedback screenshot to a viewer, who holds submissions.view but not feedback.view', function (): void {
    // THE M29 DEFECT. Before it this returned 200 with the image: the policy read the permission and never
    // the kind, so the shared route handed a PII screen capture to the three roles the dedicated
    // `/feedback/{report}/screenshot` route refuses. Same tenant deliberately — a foreign id would 404 at
    // route-model binding and never reach the gate.
    [$viewer, $attachment] = memberWithStoredObject('viewer', 'feedback_screenshot');

    $this->actingAs($viewer)->get(attachmentUrl($attachment))->assertForbidden();
});

it('still serves a feedback screenshot to an owner, so the refusal is the permission and not the kind', function (): void {
    // The other half of the pair. A fix that simply banned the kind from this route would pass the case
    // above and be wrong: `feedback.view` is what reads this image on BOTH routes that serve it.
    [$owner, $attachment] = memberWithStoredObject('owner', 'feedback_screenshot');

    $response = $this->actingAs($owner)->get(attachmentUrl($attachment));

    $response->assertOk();
    expect($response->streamedContent())->toBe('STOREDBYTES');
});

/*
|--------------------------------------------------------------------------
| M33 — the OWNER reaches the gate. This is the pair the row turns on.
|--------------------------------------------------------------------------
*/

it('refuses submission media to a form_editor who does not collaborate on its form', function (string $role): void {
    // ⛔ THE M33 DEFECT, AND THIS ASSERTION IS THE ONE THAT FLIPPED. It read `assertOk()` under M29, with a
    // comment recording that the 200 was a form_editor reading media it may not be entitled to.
    // `form_editor` and `reviewer` are the only two seeded roles holding `submissions.view` WITHOUT
    // `dashboard.org.view`, so they are the only two this narrows — which the pair below proves.
    [$caller, $attachment] = callerWithScopedObject($role, collaborates: false);

    $this->actingAs($caller)->get(attachmentUrl($attachment))->assertForbidden();
})->with(['form_editor', 'reviewer']);

it('serves that same object once the caller collaborates on its form', function (string $role): void {
    // THE POSITIVE CONTROL FOR THE NARROWING, and it is what stops the fix from being "deny these two
    // roles". The role, the object and the tenant are identical to the case above; the only difference is
    // the grant. Without this, refusing `form_editor` outright would pass the whole file.
    [$caller, $attachment] = callerWithScopedObject($role, collaborates: true);

    $response = $this->actingAs($caller)->get(attachmentUrl($attachment));

    $response->assertOk();
    expect($response->streamedContent())->toBe('STOREDBYTES');
})->with(['form_editor', 'reviewer']);

it('refuses a submission PDF export to a non-collaborating form_editor and serves it to a collaborator', function (bool $collaborates, int $status): void {
    // The per-submission PDF (`SubmissionPdfStorage`) is `is_pii = true` and written `ScanStatus::Skipped`,
    // which `servable()` admits — so it is genuinely reachable through this route, and it renders the whole
    // answer document. It is owned by the submission, so it takes the same scope as the media above.
    [$caller, $attachment] = callerWithScopedObject('form_editor', $collaborates, 'export_artifact');

    $this->actingAs($caller)->get(attachmentUrl($attachment))->assertStatus($status);
})->with([[false, 403], [true, 200]]);

it('serves submission media to its respondent, so the delegation is real and not a copy of the predicate', function (): void {
    // `SubmissionPolicy::view()` has THREE arms and collaboration is only the second. This drives the
    // third — a member who authored the submission — and it passes only because this policy delegates the
    // whole question rather than re-implementing "org-wide OR collaborates". A hand-copied predicate that
    // forgot the respondent arm would pass every other case in this file and fail here.
    $tenant = inboxTenant();

    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $form = publishedInboxForm($tenant, $owner);

    $respondent = User::factory()->create();
    enterTenant($tenant->id, $respondent->id);
    makeActiveMember($respondent, 'form_editor');

    // Written before the single actingAs, and deliberately with NO collaborator grant.
    $submission = seedInboxSubmission($form, $respondent, SubmissionStatus::Submitted, ['full_name' => 'X']);
    $attachment = Attachment::factory()->forSubmission($submission)->clean()->create();
    Storage::disk($attachment->disk)->put($attachment->path, 'STOREDBYTES');

    $this->actingAs($respondent)->get(attachmentUrl($attachment))->assertOk();
});

it('scopes a file still staged against a form field to that field s form', function (bool $collaborates, int $status): void {
    // The pre-persist window: `AttachmentStorageService::store()` writes the `form_field` alias and
    // `SubmissionDraftService` later re-points it at the submission. There is no submission to delegate to
    // yet, so the scope is the field's FORM — asserted in both directions because a staged file is the one
    // owner class that cannot reach `SubmissionPolicy` at all.
    [$caller, $attachment] = callerWithScopedObject('form_editor', $collaborates, 'staged_field');

    $this->actingAs($caller)->get(attachmentUrl($attachment))->assertStatus($status);
})->with([[false, 403], [true, 200]]);

/*
|--------------------------------------------------------------------------
| M33 — the two kinds that are NOT form-scoped, and are answered on the permission alone.
|--------------------------------------------------------------------------
*/

it('refuses an archived webhook envelope to a collaborating form_editor', function (): void {
    // ⛔ ADR-0015 §D10 / decisions.md D4. An envelope is the full payload of whatever form fired it, so it
    // crosses every per-form boundary at once and there is no form to scope it to. It was readable by all
    // five roles under `submissions.view`; it is now `webhooks.manage`, Owner/Admin.
    //
    // ⚠️ `collaborates: true` DELIBERATELY. If this refusal came from the scope rather than the permission,
    // a collaborating editor would pass — so the grant is what makes this a test of the narrowing.
    [$caller, $attachment] = callerWithScopedObject('form_editor', collaborates: true, kind: 'webhook_envelope');

    $this->actingAs($caller)->get(attachmentUrl($attachment))->assertForbidden();
});

it('serves an archived webhook envelope to an owner, who manages the endpoint it was sent to', function (): void {
    // The positive control for D4's narrowing. `WebhookPayloadArchive` writes these `ScanStatus::Skipped`
    // under a comment asserting they are "never served to a browser" — this route is what makes that
    // comment false, so the kind has to be answered deliberately rather than left in a default arm.
    [$caller, $attachment] = callerWithScopedObject('owner', collaborates: false, kind: 'webhook_envelope');

    $response = $this->actingAs($caller)->get(attachmentUrl($attachment));

    $response->assertOk();
    expect($response->streamedContent())->toBe('STOREDBYTES');
});

it('still serves a brand logo to a non-collaborating member, because those bytes are already public', function (): void {
    // Deliberately NOT narrowed and deliberately NOT scoped: `GET /branding/logo` serves these same bytes
    // UNAUTHENTICATED to email clients. Tightening this route would protect nothing, and a logo has no
    // form to be scoped to — its owner alias is `tenant`, which is not even in the morph map.
    [$caller, $attachment] = callerWithScopedObject('form_editor', collaborates: false, kind: 'branding_logo');

    $this->actingAs($caller)->get(attachmentUrl($attachment))->assertOk();
});

/*
|--------------------------------------------------------------------------
| The outermost gate.
|--------------------------------------------------------------------------
*/

it('sends a caller with no session to login rather than serving bytes', function (): void {
    [, $attachment] = memberWithStoredObject('owner', 'feedback_screenshot');

    // No actingAs. The `auth` middleware on the tenant group is the outermost gate and is asserted here
    // because nothing else in the suite asserts it for this route.
    $this->get(attachmentUrl($attachment))->assertRedirect();
});

/*
|--------------------------------------------------------------------------
| M34 — the 409 quarantine branch, which no stored-file route in the repository asserted.
|
| AttachmentController.php:43 and FeedbackController.php:75 both
| `abort_unless($attachment->virus_scan_status->servable(), 409)`, and ScanStatus's own docblock
| (app/Enums/ScanStatus.php:26-27) calls servable() the serving gate the threat model relies on. Nothing
| asserted either: BrandingLogoRouteTest.php:98 only mentions 409 in prose while asserting a 404. Delete or
| invert either guard and a pending or infected object is served with the whole suite green.
|
| It lives beside the policy cases deliberately, because it is the SECOND half of the same question: the
| policy decides who may read this object, and servable() decides whether the object may be read AT ALL.
| The guard runs after `can:view,attachment`, so a caller who fails the policy never reaches it — which is
| why both cases below use an OWNER, the principal the policy admits.
|
| POSITIVE CONTROL: 'serves respondent media to a viewer' (:162) and 'still serves a feedback screenshot to
| an owner' (:184), both of which build the same fixture with ->clean() and assert 200.
|--------------------------------------------------------------------------
*/

it('409s a stored object whose scan has not finished, for a caller the policy admits', function (): void {
    [$owner, $attachment] = memberWithStoredObject('owner', 'submission_media');
    $attachment->update(['virus_scan_status' => ScanStatus::Pending]);

    $this->actingAs($owner)->get(attachmentUrl($attachment))->assertStatus(409);
});

it('409s a quarantined object rather than serving the bytes', function (): void {
    // The arm that matters: `infected` is not "not ready yet", it is a file the scanner has positively
    // identified. servable() admits Clean and Skipped only (ScanStatus.php:31), so both non-servable cases
    // are covered and neither depends on the other's enum value.
    [$owner, $attachment] = memberWithStoredObject('owner', 'submission_media');
    $attachment->update(['virus_scan_status' => ScanStatus::Infected]);

    $this->actingAs($owner)->get(attachmentUrl($attachment))->assertStatus(409);
});
