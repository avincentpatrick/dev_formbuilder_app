<?php

declare(strict_types=1);

use App\Enums\FeedbackStatus;
use App\Models\Attachment;
use App\Models\FeedbackReport;
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
| Increment M29 — the first HTTP coverage `GET /attachments/{attachment}` has ever had.
|--------------------------------------------------------------------------
| `AttachmentPolicy::view()` is the only role gate on the authenticated media read path, and until this
| file existed nothing exercised it: `AttachmentRlsTest` asserts the DATABASE isolation four ways, and
| every other `attachments` mention under `tests/` is `TenantUrl` string-building. So the one predicate
| deciding who may read a tenant's stored bytes had no test of any kind — the same shape as the feedback
| screenshot route this increment was commissioned for, one layer out.
|
| The defect it pins: G6 wrote the policy as a flat `submissions.view` check that never read its
| `$attachment` argument, which was true of every kind G6 could produce. ADR-0015 §D6 then filed a feedback
| screenshot into the same shared table — a PII image (§D8) whose own route is gated `feedback.view`,
| Owner and Admin only. `viewer`, `reviewer` and `form_editor` all hold `submissions.view` and none holds
| `feedback.view`, so the id-addressed sibling route served exactly the roles the dedicated route refuses.
|
| ⚠️ EVERY CASE HERE USES ONE CALLER. A second actor in one test resolves its permissions from the first
| request's state, and any tenant-scoped model write after a request needs `enterTenant()` re-called
| because the request tears the RLS GUC down. All fixture writes therefore happen BEFORE the single
| `actingAs`, never between two.
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
 * A tenant, one member at `$roleName`, and a stored object of the caller's choosing whose bytes exist on
 * the fake disk. Returns the member and the attachment; the tenant host is always `acme.meridian.test`.
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
        $attachment = Attachment::factory()->clean()->create();
    }

    Storage::disk($attachment->disk)->put($attachment->path, 'STOREDBYTES');

    return [$user, $attachment];
}

/** The URL under test. Hard-coded rather than `route()`, matching the sibling suites on this host. */
function attachmentUrl(Attachment $attachment): string
{
    return "http://acme.meridian.test/attachments/{$attachment->id}";
}

it('serves respondent media to a viewer, which is the permission this route was written for', function (): void {
    // THE POSITIVE CONTROL, and it is named rather than assumed. Without it, a policy that refused
    // EVERYTHING would satisfy every other case in this file. `viewer` is the least-privileged seeded
    // role and holds `submissions.view`, so this is also the widest the default arm goes.
    [$viewer, $attachment] = memberWithStoredObject('viewer', 'submission_media');

    $response = $this->actingAs($viewer)->get(attachmentUrl($attachment));

    $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->streamedContent())->toBe('STOREDBYTES');
});

it('refuses a feedback screenshot to a viewer, who holds submissions.view but not feedback.view', function (): void {
    // THE DEFECT. Before M29 this returned 200 with the image: the policy read the permission and never
    // the kind, so the shared route handed a PII screen capture to the three roles the dedicated
    // `/feedback/{report}/screenshot` route refuses. Same tenant deliberately — a foreign id would 404 at
    // route-model binding and never reach the gate.
    [$viewer, $attachment] = memberWithStoredObject('viewer', 'feedback_screenshot');

    $this->actingAs($viewer)->get(attachmentUrl($attachment))->assertForbidden();
});

it('still serves a feedback screenshot to an owner, so the refusal is the permission and not the kind', function (): void {
    // The other half of the pair. A fix that simply banned the kind from this route would pass the case
    // above and be wrong: `feedback.view` is what reads this image on BOTH routes that serve it, and an
    // Owner holds it.
    [$owner, $attachment] = memberWithStoredObject('owner', 'feedback_screenshot');

    $response = $this->actingAs($owner)->get(attachmentUrl($attachment));

    $response->assertOk();
    expect($response->streamedContent())->toBe('STOREDBYTES');
});

it('serves a submission attachment to a form_editor, pinning the default arm against a quiet narrowing', function (): void {
    // `form_editor` holds `submissions.view`, so the default arm ADMITS it — asserted here so the `match`
    // is pinned in both directions and a future edit cannot quietly narrow the default to Owner/Admin.
    //
    // ⚠️ THIS CASE IS ALSO THE FILED-NOT-FIXED BOUNDARY, STATED AS AN ASSERTION RATHER THAN A COMMENT.
    // `SubmissionPolicy::view()` additionally requires org-wide visibility or per-form collaboration;
    // this policy requires neither, so the 200 below is a form_editor reading media it may not be
    // entitled to. That is a real defect with its own backlog row — it is larger than one enum arm, and
    // this test records the CURRENT behaviour so whoever closes it sees exactly what changes.
    [$editor, $attachment] = memberWithStoredObject('form_editor', 'submission_media');

    $this->actingAs($editor)->get(attachmentUrl($attachment))->assertOk();
});

it('sends a caller with no session to login rather than serving bytes', function (): void {
    [, $attachment] = memberWithStoredObject('owner', 'feedback_screenshot');

    // No actingAs. The `auth` middleware on the tenant group is the outermost gate and is asserted here
    // because nothing else in the suite asserts it for this route.
    $this->get(attachmentUrl($attachment))->assertRedirect();
});
