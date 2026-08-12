<?php

declare(strict_types=1);

use App\Enums\AttachmentKind;
use App\Enums\AuditEvent;
use App\Enums\FieldType;
use App\Enums\PlanTier;
use App\Enums\QueueName;
use App\Enums\ScanStatus;
use App\Enums\SubmissionPdfOutcome;
use App\Enums\SubmissionStatus;
use App\Enums\UsageMetric;
use App\Jobs\Submissions\GeneratePdfJob;
use App\Models\Attachment;
use App\Models\Audit;
use App\Models\Form;
use App\Models\Submission;
use App\Models\Tenant;
use App\Models\UsageCounter;
use App\Models\User;
use App\Notifications\Submissions\SubmissionPdfReadyNotification;
use App\Services\Attachments\AttachmentScanner;
use App\Services\Entitlements\EntitlementService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\SubmissionInboxPresenter;
use App\Services\Submissions\SubmissionPdfStorage;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantUrl;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

// The H17 write path end to end: enqueue (audit + meter + pre-flight), then the worker (render,
// scan, quota, store, notify).
//
// A note on Storage::fake(): the storage QUOTA is row-based, not disk-based — the gauge is
// `Attachment::sum('size_bytes')` under the SoftDeletes scope — so faking the disk does not
// weaken any quota assertion here. It only isolates the object writes.

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'alpha']);
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');

    Storage::fake(config('filesystems.default'));
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function pdfJobForm(Tenant $tenant, User $owner): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Intake');
    $version = $form->draftVersion;
    addFormField($version, $owner, 'applicant', FieldType::ShortText, 1);
    addFormField($version, $owner, 'notes', FieldType::LongText, 2);
    app(PublishService::class)->publish($form->refresh(), $owner);

    return $form->refresh();
}

function runPdfJob(Submission $submission, Tenant $tenant, User $user): void
{
    (new GeneratePdfJob((string) $submission->getKey(), $tenant->id, (string) $user->getKey()))->handle();
}

/**
 * The absolute tenant-subdomain URL. Tenancy resolves by subdomain
 * (`InitializeTenancyBySubdomain`), so a bare path 500s with "Hostname localhost does not include
 * a subdomain" — the `WebhookWebTest::webhookUrl()` idiom.
 */
function pdfUrl(Submission $submission): string
{
    return "http://alpha.meridian.test/submissions/{$submission->getKey()}/pdf";
}

/** Swap the scanner for one that condemns everything. */
function condemnEverything(): void
{
    app()->instance(AttachmentScanner::class, new class extends AttachmentScanner
    {
        public function scanBytes(string $contents, string $filename): ScanStatus
        {
            return ScanStatus::Infected;
        }
    });
}

it('renders, stores and emails a download link', function (): void {
    Notification::fake();

    // ⚠️ THE ASSERTION BELOW NAMES A HOST AND A PORT, SO IT MUST NAME THE app.url THEY COME FROM (H16b).
    // It read the AMBIENT value until now and passed by coincidence: `.env` says localhost:8080 on a
    // developer's machine, and CI does `cp .env.example .env`, which says the same. Pinning APP_URL in
    // phpunit.xml — so the suite stops contradicting its own CENTRAL_DOMAIN — moved the composed host to
    // alpha.meridian.test and reddened exactly this one case out of the whole suite.
    //
    // Set explicitly rather than restated, for two reasons. The two cases below already do it this way,
    // so this one was the odd one out. And localhost:8080 is the PORT-CARRYING shape: `TenantUrl` prefixing
    // a subdomain label onto an origin that has a port is the composition worth pinning here, and a
    // portless meridian.test would quietly stop exercising it.
    config(['app.url' => 'http://localhost:8080']);

    $form = pdfJobForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['applicant' => 'Ana', 'notes' => 'hello']);

    runPdfJob($submission, $this->tenant, $this->owner);

    $attachment = Attachment::query()->where('kind', AttachmentKind::ExportArtifact)->sole();

    expect($attachment->mime_type)->toBe('application/pdf')
        ->and($attachment->attachable_type)->toBe('submission')
        ->and((string) $attachment->attachable_id)->toBe((string) $submission->getKey())
        ->and($attachment->uploaded_by)->toBeNull()
        ->and($attachment->size_bytes)->toBeGreaterThan(1000)
        // The tenant-prefixed key convention AttachmentKind's docblock requires, with a
        // deterministic leaf so a regeneration overwrites rather than accumulates.
        ->and($attachment->path)->toBe("tenants/{$this->tenant->id}/export_artifact/submissions/{$submission->getKey()}.pdf");

    Storage::disk((string) config('filesystems.default'))->assertExists($attachment->path);

    Notification::assertSentOnDemand(
        SubmissionPdfReadyNotification::class,
        function (SubmissionPdfReadyNotification $n, array $channels, object $notifiable): bool {
            return $n->outcome === SubmissionPdfOutcome::Ready
                // Built on the TENANT's host from the domains table — a worker has no request, so
                // route() would emit the central domain, which PreventAccessFromCentralDomains rejects.
                // Composed label + deployment host: the `domains` row holds only `alpha`.
                && str_contains((string) $n->downloadUrl, 'alpha.localhost:8080/attachments/')
                && $notifiable->routes['mail'] === $this->owner->email;
        },
    );
});

it('derives the link scheme from app.url instead of hard-coding https', function (): void {
    // The bug both existing tenant-URL builders carry (`TenantMembershipService`,
    // `GuestDraftController` both hard-code https), which makes their links unusable in local
    // development. H17's builder does not inherit it.
    Notification::fake();
    config(['app.url' => 'http://localhost:8080']);

    $form = pdfJobForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['applicant' => 'Ana']);

    runPdfJob($submission, $this->tenant, $this->owner);

    Notification::assertSentOnDemand(
        SubmissionPdfReadyNotification::class,
        fn (SubmissionPdfReadyNotification $n): bool => str_starts_with((string) $n->downloadUrl, 'http://alpha.localhost:8080/attachments/'),
    );
});

it('supersedes: regenerating leaves ONE row, ONE object and a flat storage gauge', function (): void {
    // The chosen supersede semantics, and the reason the key is deterministic. `storage_bytes` is a
    // HARD-BLOCK gauge with no reaper anywhere in the codebase, so an accumulate design would
    // charge a tenant permanently for every click of "Regenerate".
    Notification::fake();
    $form = pdfJobForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['applicant' => 'Ana']);

    runPdfJob($submission, $this->tenant, $this->owner);
    $first = Attachment::query()->where('kind', AttachmentKind::ExportArtifact)->sole();
    $gaugeAfterFirst = app(EntitlementService::class)->countGauge(UsageMetric::StorageBytes);

    runPdfJob($submission->refresh(), $this->tenant, $this->owner);
    $rows = Attachment::query()->where('kind', AttachmentKind::ExportArtifact)->get();

    // ANTI-VACUITY: assert the row EXISTS as well as that there is one of it. A count of 1 would
    // also be satisfied by a mutation that wrote nothing the second time and left the first behind
    // — and by one that wrote nothing at all if the first assertion were dropped too.
    expect($rows)->toHaveCount(1)
        ->and((string) $rows->first()->getKey())->toBe((string) $first->getKey())
        ->and($rows->first()->size_bytes)->toBeGreaterThan(1000)
        ->and(app(EntitlementService::class)->countGauge(UsageMetric::StorageBytes))->toBe($gaugeAfterFirst);

    expect(Storage::disk((string) config('filesystems.default'))->files("tenants/{$this->tenant->id}/export_artifact/submissions"))
        ->toHaveCount(1);
});

it('writes nothing at all when the scanner condemns the render', function (): void {
    // Strictly stronger than the upload path, which stores first and quarantines at read time with
    // a 409. A generated file that fails its scan never reaches disk.
    Notification::fake();
    condemnEverything();

    $form = pdfJobForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['applicant' => 'Ana']);

    runPdfJob($submission, $this->tenant, $this->owner);

    expect(Attachment::query()->where('kind', AttachmentKind::ExportArtifact)->count())->toBe(0)
        ->and(Storage::disk((string) config('filesystems.default'))->allFiles())->toBe([]);

    Notification::assertSentOnDemand(
        SubmissionPdfReadyNotification::class,
        fn (SubmissionPdfReadyNotification $n): bool => $n->outcome === SubmissionPdfOutcome::Failed
            && $n->downloadUrl === null,
    );
});

it('records the scanner verdict rather than a hardcoded Skipped', function (): void {
    // WebhookPayloadArchive hardcodes ScanStatus::Skipped, justified by "never served to a
    // browser". A PDF is served to a browser, so H17 stores what the scanner actually said — which
    // in Phase-1 IS Skipped, but because the scanner said so, not because a literal was typed in.
    Notification::fake();
    $form = pdfJobForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['applicant' => 'Ana']);

    app()->instance(AttachmentScanner::class, new class extends AttachmentScanner
    {
        public function scanBytes(string $contents, string $filename): ScanStatus
        {
            return ScanStatus::Clean;
        }
    });

    runPdfJob($submission, $this->tenant, $this->owner);

    expect(Attachment::query()->where('kind', AttachmentKind::ExportArtifact)->sole()->virus_scan_status)
        ->toBe(ScanStatus::Clean);
});

it('reports a quota refusal by email and does NOT retry', function (): void {
    // A limit is a business outcome, not a bug. Rethrowing would burn $maxExceptions = 3 full
    // re-renders on a refusal that cannot change, and — because there is no notification bell,
    // no polling and no broadcast anywhere in this app — would tell the user nothing at all.
    Notification::fake();
    $form = pdfJobForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['applicant' => 'Ana']);

    // A plan whose storage allowance is already exhausted by an unrelated attachment.
    assignPlanTier(PlanTier::Free);
    Attachment::create([
        'attachable_type' => 'submission', 'attachable_id' => $submission->getKey(),
        'kind' => AttachmentKind::SubmissionFile, 'disk' => 'local', 'path' => 'x/y.bin',
        'size_bytes' => 500 * 1024 * 1024, 'virus_scan_status' => ScanStatus::Skipped, 'uploaded_by' => null,
    ]);

    // The job COMPLETES — no exception escapes — which is what stops the retry ladder.
    runPdfJob($submission, $this->tenant, $this->owner);

    expect(Attachment::query()->where('kind', AttachmentKind::ExportArtifact)->count())->toBe(0);

    Notification::assertSentOnDemand(
        SubmissionPdfReadyNotification::class,
        fn (SubmissionPdfReadyNotification $n): bool => $n->outcome === SubmissionPdfOutcome::QuotaExceeded,
    );
});

it('charges only the DELTA when regenerating, not the whole document again', function (): void {
    // Without the delta the second run would ask for the full size on top of a gauge that already
    // counts the first — so a tenant with room for exactly one PDF could never replace it.
    //
    // ANTI-VACUITY, and this test needed it. It first asserted `assertSentTimes(…, 2)` plus an
    // unchanged row — both of which a full-size charge ALSO satisfies, because a refusal still
    // sends a notification (a QuotaExceeded one) and still leaves the first row untouched. The
    // mutation pass caught it green. What actually distinguishes the two is the OUTCOME, so that
    // is what is asserted now.
    Notification::fake();
    $form = pdfJobForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['applicant' => 'Ana']);

    runPdfJob($submission, $this->tenant, $this->owner);
    $size = (int) Attachment::query()->where('kind', AttachmentKind::ExportArtifact)->sole()->size_bytes;

    // A plan with room for this document and almost nothing more.
    $plan = assignPlanTier(PlanTier::Free)->plan;
    $plan->forceFill(['quotas' => array_merge($plan->quotas, ['storage_bytes' => $size + 64])])->save();
    app(EntitlementService::class)->forget();

    runPdfJob($submission->refresh(), $this->tenant, $this->owner);

    // BOTH runs must report Ready. A full-size charge would make the second one QuotaExceeded,
    // while still sending a notification and still leaving the stored row untouched.
    Notification::assertSentTimes(SubmissionPdfReadyNotification::class, 2);
    Notification::assertSentOnDemandTimes(SubmissionPdfReadyNotification::class, 2);
    expect(Attachment::query()->where('kind', AttachmentKind::ExportArtifact)->sole()->size_bytes)->toBe($size);

    $outcomes = [];
    Notification::assertSentOnDemand(
        SubmissionPdfReadyNotification::class,
        function (SubmissionPdfReadyNotification $n) use (&$outcomes): bool {
            $outcomes[] = $n->outcome;

            return true;
        },
    );

    expect($outcomes)->toBe([SubmissionPdfOutcome::Ready, SubmissionPdfOutcome::Ready]);
});

it('stays silent when the submission has gone', function (): void {
    // Deleted between enqueue and execution. Nothing to render and nobody to apologise to —
    // the one exit that does NOT email, unlike every failure path above.
    Notification::fake();

    (new GeneratePdfJob('019f0000-0000-7000-8000-000000000000', $this->tenant->id, (string) $this->owner->getKey()))->handle();

    Notification::assertNothingSent();
    expect(Attachment::query()->count())->toBe(0);
});

it('rides the exports queue, which had no consumer until now', function (): void {
    Queue::fake();
    $form = pdfJobForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['applicant' => 'Ana']);

    $this->actingAs($this->owner)->post(pdfUrl($submission))->assertRedirect();

    Queue::assertPushedOn(QueueName::Exports->value, GeneratePdfJob::class);
});

it('writes the audit row and meters the export at ENQUEUE, where the actor is live', function (): void {
    // AuditLogger hard-codes is_system_action = false and resolves the actor from Auth::id(), so a
    // row written on a worker is malformed — H12a met exactly this and chose to write no audit at
    // all. Writing it in-request is how H17 gets a well-formed one instead. First call site of
    // AuditEvent::Exported, which has been in the enum and its CHECK constraint since H4 unused.
    Queue::fake();
    $form = pdfJobForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['applicant' => 'Ana']);

    $this->actingAs($this->owner)->post(pdfUrl($submission))
        ->assertRedirect()
        ->assertSessionHas('toast');

    // The request leaves its own RLS GUC behind, so a DB assertion afterwards reads under NO
    // tenant context and sees zero rows. Re-entering is the established web-test idiom
    // (`WebhookWebTest.php:142` and eight siblings).
    enterTenant($this->tenant->id, $this->owner->id);

    $audit = Audit::query()->where('event', AuditEvent::Exported)->sole();

    expect($audit->auditable_type)->toBe('submission')
        ->and((string) $audit->auditable_id)->toBe((string) $submission->getKey())
        ->and((string) $audit->user_id)->toBe((string) $this->owner->getKey())
        ->and($audit->new_values)->toBe(['format' => 'pdf']);

    expect((int) UsageCounter::query()->where('metric', UsageMetric::ExportsCount)->value('value'))->toBe(1);
});

it('refuses in-request when the tenant is already at its storage ceiling', function (): void {
    // The pre-flight. QuotaExceededException renders as a 402 / error toast only when there IS a
    // request; thrown on the worker it is an invisible job failure. This catches the common case
    // before any work is queued.
    Queue::fake();
    $form = pdfJobForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['applicant' => 'Ana']);

    assignPlanTier(PlanTier::Free);
    Attachment::create([
        'attachable_type' => 'submission', 'attachable_id' => $submission->getKey(),
        'kind' => AttachmentKind::SubmissionFile, 'disk' => 'local', 'path' => 'x/y.bin',
        'size_bytes' => 500 * 1024 * 1024, 'virus_scan_status' => ScanStatus::Skipped, 'uploaded_by' => null,
    ]);

    $this->actingAs($this->owner)->post(pdfUrl($submission))->assertRedirect();

    Queue::assertNothingPushed();
    enterTenant($this->tenant->id, $this->owner->id);
    expect(Audit::query()->where('event', AuditEvent::Exported)->count())->toBe(0);
});

it('refuses a user who cannot view the submission', function (): void {
    Queue::fake();
    $form = pdfJobForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['applicant' => 'Ana']);

    // A signed-in user who is NOT a member of this tenant. Chosen over revoking the permission
    // because EVERY seeded role carries `submissions.view` (owner/admin/form_editor/reviewer/
    // viewer all list it), a user-level revoke does nothing against a role-granted permission, and
    // revoking it from the ROLE fails outright — `role_has_permissions` is written on the
    // privileged connection by RolePermissionSeeder, so a test-side delete is refused by RLS.
    // Non-membership is also the more valuable assertion: it is the tenant boundary, not just the
    // permission bit.
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->post(pdfUrl($submission))->assertForbidden();

    Queue::assertNothingPushed();
    enterTenant($this->tenant->id, $this->owner->id);
    expect(Audit::query()->where('event', AuditEvent::Exported)->count())->toBe(0);
});

it('composes the host from the deployment, defaulting the scheme CLOSED', function (): void {
    // Both halves of the two precedents' defect, pinned. `domains.domain` holds `alpha` — a bare
    // label — so a builder that interpolates it directly emits `https://alpha/…`, which resolves
    // nowhere. And an unparseable app.url must not silently downgrade a link to cleartext.
    config(['app.url' => 'https://meridian.test']);
    expect(TenantUrl::to($this->tenant, 'attachments/x'))->toBe('https://alpha.meridian.test/attachments/x');

    config(['app.url' => '']);
    expect(TenantUrl::to($this->tenant, '/attachments/x'))->toStartWith('https://alpha.');
});

it('keeps a pdf download link on the app host, never on a custom domain', function (): void {
    // ⚠️ THIS ASSERTION IS THE INVERSE OF WHAT H17 WROTE, AND THE INVERSION IS THE POINT.
    //
    // H17 pinned `it leaves an already-qualified custom domain alone` — any dotted value used verbatim,
    // "the shape H22 will introduce". That guess was right about the STORAGE and wrong about the
    // CONSEQUENCE. ADR-0009 §D2 scopes custom domains to public forms, not the admin app, so H22a swapped
    // the identification middleware on the guest-runtime group ONLY. A pdf download lands on
    // /attachments/{...} — an AUTHENTICATED route — so a link built on the custom host would 302 its
    // recipient to the central app. TenantUrl::to() is the app arm and must never reach for a custom row.
    //
    // TenantUrl::toPublic() is where a custom domain is used verbatim; TenantHostTest covers both arms.
    $other = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'default_locale' => 'en']);
    $other->domains()->create(['domain' => 'beta']);
    customDomain($other, 'forms.acme.com');
    config(['app.url' => 'https://meridian.test']);

    expect(TenantUrl::to($other, 'attachments/x'))->toBe('https://beta.meridian.test/attachments/x')
        // …and the same tenant's respondent-facing links DO use it, so this is a routing decision rather
        // than the custom domain being ignored everywhere.
        ->and(TenantUrl::toPublic($other, 'f/resume/tok'))->toBe('https://forms.acme.com/f/resume/tok');
});

it('will not put a half-provisioned custom domain in an outbound link', function (bool $verified, bool $activated): void {
    // The complement, and the reason the scope is global rather than a filter at each call site: an
    // emailed link is built on a queued worker, far from any request, by code that has no idea a
    // `domains` row can be in an unusable state.
    $other = Tenant::create(['name' => 'Gamma', 'slug' => 'gamma', 'default_locale' => 'en']);
    $other->domains()->create(['domain' => 'gamma']);
    customDomain($other, 'forms.gamma-example.com', $verified, $activated);
    config(['app.url' => 'https://meridian.test']);

    expect(TenantUrl::to($other, 'attachments/x'))->toBe('https://gamma.meridian.test/attachments/x')
        ->and(TenantUrl::toPublic($other, 'f/resume/tok'))->toBe('https://gamma.meridian.test/f/resume/tok');
})->with([
    'pending' => [false, false],
    'verified but not activated' => [true, false],
]);

it('surfaces the stored PDF on the submission detail page', function (): void {
    // The only in-app surface for the artifact — there is no notification bell, so a user who
    // reloads the page must be able to find it without the email.
    Notification::fake();
    $form = pdfJobForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['applicant' => 'Ana']);

    $before = app(SubmissionInboxPresenter::class)->detail($this->owner, $submission);
    expect($before['pdf'])->toBeNull();

    runPdfJob($submission, $this->tenant, $this->owner);

    $after = app(SubmissionInboxPresenter::class)->detail($this->owner, $submission->refresh());
    $stored = app(SubmissionPdfStorage::class)->existingFor($submission);

    expect($after['pdf'])->not->toBeNull()
        ->and($after['pdf']['id'])->toBe((string) $stored->getKey())
        ->and($after['pdf']['size_bytes'])->toBe((int) $stored->size_bytes);
});
