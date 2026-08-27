<?php

declare(strict_types=1);

use App\Enums\AttachmentKind;
use App\Enums\AuditEvent;
use App\Enums\FeedbackStatus;
use App\Enums\ScanStatus;
use App\Models\Audit;
use App\Models\FeedbackReport;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Admin\SuperAdminService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment I7a — the platform feedback support console (PRD Feature #11 / RBAC §9).
|--------------------------------------------------------------------------
| Covers the console's four gate arms, the cross-tenant list, the transitions and the audit row they
| leave, and the two negatives that matter: an illegal transition is refused, and an unknown id 404s.
|
| ── HARNESS SUBTLETY, AND IT IS THE REASON THIS FILE LOOKS UNUSUAL ────────────────────────────────────
| `listFeedback()` reads on the `pgsql_superadmin` connection, which is SEPARATE from the default one —
| so it cannot see rows written inside RefreshDatabase's uncommitted transaction. Ordinary factory rows
| are therefore invisible to the very code under test, and a naive version of this file would assert an
| empty list and pass. The fixtures are seeded COMMITTED on the privileged connection with recognisable
| `@feedbackconsoletest.local` markers, following SuperAdminBypassTest.
|
| ⚠️ **BUT THEY ARE CLEANED UP, AND THAT IS WHERE THIS FILE DEPARTS FROM ITS OWN PRECEDENT.**
| SuperAdminBypassTest leaves its rows behind, reasoning that a privileged DELETE would deadlock against
| a test's own open transaction. That held only because it commits `users` and nothing else. This file
| commits **TENANTS**, which are global state other suites legitimately assert over — and leaving them
| behind turned NINE unrelated tests red in CI on a locally-green tree (`DemoSeederIdempotencyTest` pins
| the exact slug list; three sweep-command tests iterate every tenant). The deadlock objection is
| answered by WHEN rather than by giving up: the purge is registered through
| `beforeApplicationDestroyed()`, which Laravel runs in registration order AFTER RefreshDatabase's own
| rollback (registered during `setUp`) — so by then the test transaction is gone and no locks remain.
|
| ⚠️ **ONE TRANSITION PER TEST — DO NOT CHAIN THEM.** The consequence of the above runs in both
| directions: a transition's UPDATE lands on the DEFAULT connection inside the uncommitted transaction, so
| a SECOND PATCH in the same test re-reads the report through `findFeedback()` on the elevated connection
| and sees the ORIGINAL committed status. The second transition is then validated against the wrong `from`
| and is usually refused — silently, because a refusal is also a redirect-back. Each test therefore starts
| from a committed fixture already in the state it needs. This is a harness artifact and not a product
| behaviour; in production both writes are committed.
*/

/**
 * A committed tenant the privileged connection can actually see.
 *
 * ⚠️ **`Tenant::on('pgsql_privileged')` DOES NOT WORK, and it fails silently.** Stancl's base model uses
 * the `CentralConnection` trait, whose `getConnectionName()` returns
 * `config('tenancy.database.central_connection')` UNCONDITIONALLY — so the connection override is
 * discarded and the row lands on the default connection inside RefreshDatabase's uncommitted
 * transaction. The write appears to succeed; the failure surfaces later and somewhere else, as an FK
 * violation when the committed `feedback_reports` insert cannot find its tenant. Hence the query builder,
 * which has no model to override it. (`tenants` is RLS-exempt, so the row is readable afterwards from any
 * connection.)
 */
function consoleTenant(string $name, string $slug): Tenant
{
    $id = Uuid::uuid7()->toString();

    DB::connection('pgsql_privileged')->table('tenants')->insert([
        'id' => $id,
        'name' => $name,
        'slug' => $slug,
        'status' => 'active',
        'data' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /** @var Tenant $tenant */
    $tenant = Tenant::query()->findOrFail($id);

    return $tenant;
}

/** A committed user, likewise. */
function consoleUser(string $email): User
{
    /** @var User $user */
    $user = User::on('pgsql_privileged')->forceCreate([
        'name' => 'Console Reporter',
        'email' => $email,
        'password' => Hash::make(Str::random(40)),
        'email_verified_at' => now(),
    ]);

    return $user;
}

/** A committed feedback report. `status` is written from the enum — the column has no CHECK constraint. */
function consoleReport(Tenant $tenant, User $user, FeedbackStatus $status, string $remarks): FeedbackReport
{
    /** @var FeedbackReport $report */
    $report = FeedbackReport::on('pgsql_privileged')->forceCreate([
        'id' => Uuid::uuid7()->toString(),
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'route' => '/dashboard',
        'remarks' => $remarks,
        'browser_info' => ['userAgent' => 'PestUA', 'viewport' => '1440x900'],
        'status' => $status->value,
        'submitted_at' => now(),
    ]);

    return $report;
}

/** The bytes {@see consoleScreenshot()} stores, so a case can assert the body it got is the body it wrote. */
const CONSOLE_SCREENSHOT_BYTES = 'CONSOLE-SCREENSHOT-BYTES';

/**
 * A committed screenshot for a committed report, plus the pointer that links them and the object on disk.
 *
 * ⚠️ **THE ATTACHMENT HAS TO BE COMMITTED TOO, AND THE REASON IS AN FK RATHER THAN A CONNECTION.**
 * `feedback_reports.screenshot_attachment_id` is a real foreign key onto `attachments`, so pointing a
 * COMMITTED report at a row that only exists inside RefreshDatabase's open transaction makes the privileged
 * UPDATE wait on a lock the test itself is holding — a hang, not an error. Both rows therefore go in on
 * `pgsql_privileged`, which is a superuser and bypasses RLS.
 *
 * The query builder rather than the model, for the reason {@see consoleTenant()} gives at length: a model's
 * traits and global scopes get a vote on the connection and on `tenant_id`, and here they would be voting
 * with no tenant context set at all.
 *
 * **Nothing is added to {@see purgeCommittedFeedbackFixtures()} and that is checked, not assumed:**
 * `attachments.tenant_id` is `cascadeOnDelete`, and the purge deletes the reports before the tenants — so
 * the tenant delete takes the attachment with it. Caller must have called `Storage::fake('local')` first,
 * or this writes a real object into the local disk.
 */
function consoleScreenshot(FeedbackReport $report, ScanStatus $scan = ScanStatus::Clean): string
{
    $id = Uuid::uuid7()->toString();
    $path = "tenants/{$report->tenant_id}/feedback_screenshot/".date('Ym')."/{$id}.png";
    $connection = DB::connection('pgsql_privileged');

    $connection->table('attachments')->insert([
        'id' => $id,
        'tenant_id' => $report->tenant_id,
        // No morph-map entry and none needed: there is no DB FK on the morph columns and the console never
        // resolves the owner back to a class. This is the alias production writes.
        'attachable_type' => 'feedback_report',
        'attachable_id' => $report->id,
        'kind' => AttachmentKind::FeedbackScreenshot->value,
        'disk' => 'local',
        'path' => $path,
        'original_filename' => 'capture.png',
        'mime_type' => 'image/png',
        'size_bytes' => strlen(CONSOLE_SCREENSHOT_BYTES),
        'checksum_sha256' => hash('sha256', CONSOLE_SCREENSHOT_BYTES),
        'width' => 1_600,
        'height' => 900,
        'is_encrypted_at_rest' => false,
        'is_pii' => true,
        // Defaults to Clean rather than to the column default: `ScanStatus::servable()` gates the stream,
        // and a `pending` row 409s before any of this reaches the disk. The parameter exists so the
        // quarantine case can ask for exactly that.
        'virus_scan_status' => $scan->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // No `updated_at` in the SET list: `feedback_reports` carries `submitted_at` and `resolved_at` and no
    // Eloquent timestamps at all, so naming one is an undefined-column error rather than a no-op.
    $connection->table('feedback_reports')->where('id', $report->id)->update([
        'screenshot_attachment_id' => $id,
    ]);

    Storage::disk('local')->put($path, CONSOLE_SCREENSHOT_BYTES);

    return $path;
}

function adminUrl(string $path): string
{
    return 'http://'.config('tenancy.central_domain').'/admin'.$path;
}

beforeEach(function (): void {
    TenantContext::flush();

    // Registered here, not in afterEach: RefreshDatabase registers its rollback the same way during
    // setUp — earlier — and Laravel runs these in order, so this fires once the transaction is gone.
    $this->beforeApplicationDestroyed(purgeCommittedFeedbackFixtures(...));

    // I8a — the console carries `step-up`; without a fresh confirmation every request here 302s to
    // /user/confirm-password instead of rendering. See tests/Pest.php.
    confirmPasswordNow();
});

it('renders the console for an enrolled super-admin', function (): void {
    $this->withoutVite();

    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    $this->actingAs($admin)->get(adminUrl('/feedback'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Feedback', false)
            ->has('data')
            ->where('meta.per_page', 25)
            ->has('filters.statuses', 4)
            ->has('filters.tenants'));
});

it('redirects a super-admin who has not enrolled in 2FA', function (): void {
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs($admin)->get(adminUrl('/feedback'))->assertRedirect(route('admin.mfa.setup'));
});

it('404s the console for a non-super-admin', function (): void {
    // 404 rather than 403: the console does not disclose its own existence (EnsureSuperAdmin).
    $this->actingAs(User::factory()->create())->get(adminUrl('/feedback'))->assertNotFound();
});

it('redirects a guest', function (): void {
    $this->get(adminUrl('/feedback'))->assertRedirect();
});

it('reads feedback across every tenant through the elevated carve-out', function (): void {
    $this->withoutVite();

    $tenantA = consoleTenant('Console Alpha', 'console-alpha-'.Str::random(6));
    $tenantB = consoleTenant('Console Beta', 'console-beta-'.Str::random(6));
    $reporter = consoleUser(Str::random(8).'@feedbackconsoletest.local');

    consoleReport($tenantA, $reporter, FeedbackStatus::New, 'Alpha says the exports are slow.');
    consoleReport($tenantB, $reporter, FeedbackStatus::Resolved, 'Beta says the map is fixed now.');

    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    $response = $this->actingAs($admin)->get(adminUrl('/feedback'))->assertOk();

    /** @var array<string, mixed> $props */
    $props = $response->viewData('page')['props'];
    /** @var list<array<string, mixed>> $rows */
    $rows = $props['data'];

    $byRemarks = array_column($rows, null, 'remarks');

    // Both workspaces in one list — this is the whole point of the carve-out.
    expect($byRemarks)->toHaveKey('Alpha says the exports are slow.')
        ->and($byRemarks)->toHaveKey('Beta says the map is fixed now.')
        ->and($byRemarks['Alpha says the exports are slow.']['tenant_name'])->toBe('Console Alpha')
        ->and($byRemarks['Beta says the map is fixed now.']['tenant_name'])->toBe('Console Beta')
        // The reporter's name comes from `users`, read through ITS bypass in the same transaction.
        ->and($byRemarks['Alpha says the exports are slow.']['reporter']['name'])->toBe('Console Reporter');

    // Each row carries the verbs the server will actually accept — never a client-side re-derivation.
    expect(array_column($byRemarks['Alpha says the exports are slow.']['transitions'], 'value'))
        ->toBe(['reviewed', 'wont_fix'])
        ->and(array_column($byRemarks['Beta says the map is fixed now.']['transitions'], 'value'))
        ->toBe(['reviewed']);
});

it('filters the console by status', function (): void {
    $this->withoutVite();

    $tenant = consoleTenant('Console Filter', 'console-filter-'.Str::random(6));
    $reporter = consoleUser(Str::random(8).'@feedbackconsoletest.local');
    $marker = 'Filter marker '.Str::random(10);

    consoleReport($tenant, $reporter, FeedbackStatus::WontFix, $marker);

    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    $response = $this->actingAs($admin)->get(adminUrl('/feedback').'?status=wont_fix')->assertOk();

    /** @var list<array<string, mixed>> $rows */
    $rows = $response->viewData('page')['props']['data'];
    $statuses = array_unique(array_column($rows, 'status'));

    expect($statuses)->toBe(['wont_fix'])
        ->and(array_column($rows, 'remarks'))->toContain($marker);
});

it('transitions a report and audits it into the affected tenant own ledger', function (): void {
    $tenant = consoleTenant('Console Audit', 'console-audit-'.Str::random(6));
    $reporter = consoleUser(Str::random(8).'@feedbackconsoletest.local');
    $report = consoleReport($tenant, $reporter, FeedbackStatus::New, 'Audit me '.Str::random(8));

    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    $this->actingAs($admin)
        ->patch(adminUrl("/feedback/{$report->id}"), ['status' => FeedbackStatus::Reviewed->value])
        // Without this a REFUSED transition would also pass `assertRedirect()` — the error path is a
        // redirect-back too, which is how a silently-rejected transition looks like a successful one.
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    // Read the row back under the affected tenant's own context — the write went through the app
    // connection with that context adopted, not through an elevated bypass.
    enterTenant($tenant->id, $reporter->id);

    $fresh = FeedbackReport::query()->findOrFail($report->id);
    expect($fresh->status)->toBe(FeedbackStatus::Reviewed)
        // `reviewed` is not terminal, so nothing is stamped yet.
        ->and($fresh->resolved_at)->toBeNull()
        ->and($fresh->resolved_by)->toBeNull();

    /** @var Audit $audit */
    $audit = Audit::query()->where('auditable_type', 'feedback_reports')->latest('id')->firstOrFail();

    expect($audit->tenant_id)->toBe($tenant->id)
        ->and($audit->event)->toBe(AuditEvent::Updated)
        ->and($audit->auditable_id)->toBe($report->id)
        ->and($audit->user_id)->toBe($admin->id)
        ->and($audit->old_values)->toMatchArray(['status' => 'new'])
        ->and($audit->new_values)->toMatchArray(['status' => 'reviewed']);
});

it('stamps the resolution when a report closes', function (): void {
    $tenant = consoleTenant('Console Close', 'console-close-'.Str::random(6));
    $reporter = consoleUser(Str::random(8).'@feedbackconsoletest.local');
    $report = consoleReport($tenant, $reporter, FeedbackStatus::Reviewed, 'Close me '.Str::random(8));

    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    $this->actingAs($admin)
        ->patch(adminUrl("/feedback/{$report->id}"), ['status' => FeedbackStatus::Resolved->value])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    enterTenant($tenant->id, $reporter->id);
    $closed = FeedbackReport::query()->findOrFail($report->id);
    expect($closed->status)->toBe(FeedbackStatus::Resolved)
        ->and($closed->resolved_at)->not->toBeNull()
        ->and($closed->resolved_by)->toBe($admin->id);
});

it('clears the resolution when a closed report is re-opened', function (): void {
    // Starts from a COMMITTED `resolved` fixture rather than chaining onto the test above — see the
    // one-transition-per-test note in this file's header. Also the `wont_fix` arm, so the decline path
    // is proved to stamp and clear exactly as `resolved` does (FeedbackStatus::isTerminal()).
    $tenant = consoleTenant('Console Reopen', 'console-reopen-'.Str::random(6));
    $reporter = consoleUser(Str::random(8).'@feedbackconsoletest.local');
    $closer = consoleUser(Str::random(8).'@feedbackconsoletest.local');
    $report = consoleReport($tenant, $reporter, FeedbackStatus::WontFix, 'Reopen me '.Str::random(8));

    // Give it a resolution to clear, committed alongside the row itself.
    DB::connection('pgsql_privileged')->table('feedback_reports')->where('id', $report->id)->update([
        'resolved_at' => now(),
        'resolved_by' => $closer->id,
    ]);

    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    $this->actingAs($admin)
        ->patch(adminUrl("/feedback/{$report->id}"), ['status' => FeedbackStatus::Reviewed->value])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    // Re-opening CLEARS the stamp: those columns mean "when and by whom was this CLOSED", and it is not.
    enterTenant($tenant->id, $reporter->id);
    $reopened = FeedbackReport::query()->findOrFail($report->id);
    expect($reopened->status)->toBe(FeedbackStatus::Reviewed)
        ->and($reopened->resolved_at)->toBeNull()
        ->and($reopened->resolved_by)->toBeNull();
});

it('refuses a transition the lifecycle does not offer', function (): void {
    $tenant = consoleTenant('Console Illegal', 'console-illegal-'.Str::random(6));
    $reporter = consoleUser(Str::random(8).'@feedbackconsoletest.local');
    // `new` → `resolved` skips triage; the console never offers it, so reaching it means a hand-made request.
    $report = consoleReport($tenant, $reporter, FeedbackStatus::New, 'Illegal '.Str::random(8));

    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    $this->actingAs($admin)
        ->patch(adminUrl("/feedback/{$report->id}"), ['status' => FeedbackStatus::Resolved->value])
        ->assertSessionHasErrors('admin');

    enterTenant($tenant->id, $reporter->id);
    expect(FeedbackReport::query()->findOrFail($report->id)->status)->toBe(FeedbackStatus::New);
});

it('404s an unknown report id rather than 500ing', function (): void {
    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    $this->actingAs($admin)
        ->patch(adminUrl('/feedback/'.Uuid::uuid7()->toString()), ['status' => FeedbackStatus::Reviewed->value])
        ->assertNotFound();
});

it('rejects a status that is not in the enum', function (): void {
    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    $this->actingAs($admin)
        ->patch(adminUrl('/feedback/'.Uuid::uuid7()->toString()), ['status' => 'archived'])
        ->assertSessionHasErrors('status');
});

it('reads a screenshot through adopted tenant context, without an attachments bypass', function (): void {
    // The negative half of this is structural and lives in FeedbackRlsTest: `attachments` has NO
    // super-admin policy, so if this passes, it passed by adopting the tenant's context.
    $tenant = consoleTenant('Console Shot', 'console-shot-'.Str::random(6));
    $reporter = consoleUser(Str::random(8).'@feedbackconsoletest.local');
    $report = consoleReport($tenant, $reporter, FeedbackStatus::New, 'No picture '.Str::random(8));

    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    // This report has no screenshot, so 404 is the correct answer — and proves the lookup itself resolved.
    $this->actingAs($admin)->get(adminUrl("/feedback/{$report->id}/screenshot"))->assertNotFound();

    expect(app(SuperAdminService::class)->feedbackScreenshot($report))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Increment M35 — the four gate arms of the screenshot route itself.
|--------------------------------------------------------------------------
| The console INDEX has carried all three denials since I7a (above). The screenshot route carried none of
| them, and it is the one that streams bytes: every tenant's feedback screenshots, `is_pii = true`, on the
| central host. The three routes do inherit their middleware from one group, so the index's denials pin the
| group transitively — but they cannot see a route DECLARED IN THE WRONG GROUP, which is the cheap
| mutation, and `AdminConsoleGateTest` is the structural half that can. These are the behavioural half:
| what a refused caller actually receives at this URI.
|
| ⛔ THE 404 ARM NEEDS A REAL SCREENSHOT AND THAT IS NOT BELT-AND-BRACES. `EnsureSuperAdmin` answers 404
| for non-disclosure and the controller answers 404 for a report it cannot resolve — the same status from
| two different decisions. A non-super-admin case written against a random id, or against a report with no
| screenshot (the only fixture this file could build before now), PASSES WITH THE MIDDLEWARE DELETED. The
| tenant-side twin states the identical trap in its own words at `tests/Feature/Tenant/FeedbackTest.php`.
| So that one case builds a report that a super-admin really does get 200 bytes from — which is the
| positive control immediately below it, and is also the first test in this repository ever to drive this
| route to a success.
|
| The other three arms answer with a REDIRECT, which no 404 can be confused with, so they use a random id
| and never pay for a committed fixture. Stated rather than left to be re-derived by the next reader.
*/

it('streams a cross-tenant screenshot to an enrolled super-admin', function (): void {
    Storage::fake('local');

    $tenant = consoleTenant('Console Shutter', 'console-shutter-'.Str::random(6));
    $reporter = consoleUser(Str::random(8).'@feedbackconsoletest.local');
    $report = consoleReport($tenant, $reporter, FeedbackStatus::New, 'A picture of the problem '.Str::random(8));
    consoleScreenshot($report);

    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    $response = $this->actingAs($admin)->get(adminUrl("/feedback/{$report->id}/screenshot"))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    // The bytes, not just the status: the console reads them by ADOPTING the reporting tenant's context
    // (there is no super-admin carve-out on `attachments`, which FeedbackRlsTest pins from the other side),
    // so a 200 with an empty body would mean the adoption silently failed.
    expect($response->streamedContent())->toBe(CONSOLE_SCREENSHOT_BYTES);
});

it('404s a screenshot for a non-super-admin, on a report that streams for a super-admin', function (): void {
    // ⚠️ THE POSITIVE CONTROL IS THE CASE DIRECTLY ABOVE, and this test is worthless without it: the same
    // URI, the same fixture, 200 for the right caller. Delete `superadmin` from the group and this request
    // reaches the controller, which resolves the report AND the screenshot and streams it — so the 404
    // below can only have come from the gate.
    Storage::fake('local');

    $tenant = consoleTenant('Console Shutter', 'console-shutter-'.Str::random(6));
    $reporter = consoleUser(Str::random(8).'@feedbackconsoletest.local');
    $report = consoleReport($tenant, $reporter, FeedbackStatus::New, 'A picture of the problem '.Str::random(8));
    consoleScreenshot($report);

    // 404 rather than 403, like the index: the console does not disclose its own existence.
    $this->actingAs(User::factory()->create())
        ->get(adminUrl("/feedback/{$report->id}/screenshot"))
        ->assertNotFound();
});

it('409s a console screenshot whose scan has not cleared', function (): void {
    // ⛔ NOT AN AUTHORIZATION ARM, AND IN SCOPE FOR EXACTLY THAT REASON. M34 pinned this guard on the two
    // routes it was looking at — `FeedbackController.php:75` and `AttachmentController.php:43` — and the
    // console's own copy at `FeedbackConsoleController.php:78` was the third, asserted by nothing. Deleting
    // that line served quarantined bytes to the one principal who reads across every tenant, with the whole
    // repository green. The rule M34 leaves behind is to ask the question of EVERY gate on a route you
    // touch rather than only the ones the row names, and this is that question answered here.
    //
    // The positive control is the streaming case above: same URI, same caller, same fixture, one column
    // apart. Infected rather than Pending, because Phase-1's own pipeline lands screenshots on `skipped`
    // — which IS servable — so `pending` would be a state the product never reaches.
    Storage::fake('local');

    $tenant = consoleTenant('Console Quarantine', 'console-quarantine-'.Str::random(6));
    $reporter = consoleUser(Str::random(8).'@feedbackconsoletest.local');
    $report = consoleReport($tenant, $reporter, FeedbackStatus::New, 'A picture that failed a scan '.Str::random(8));
    consoleScreenshot($report, ScanStatus::Infected);

    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    $this->actingAs($admin)
        ->get(adminUrl("/feedback/{$report->id}/screenshot"))
        ->assertStatus(409);
});

it('sends a super-admin who has not enrolled in 2FA to enrollment rather than to the bytes', function (): void {
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs($admin)
        ->get(adminUrl('/feedback/'.Uuid::uuid7()->toString().'/screenshot'))
        ->assertRedirect(route('admin.mfa.setup'));
});

it('bounces a super-admin whose password confirmation has gone stale', function (): void {
    // Overrides this file's beforeEach, which confirms at 0 seconds ago. `auth.step_up_timeout` is 900.
    confirmPasswordNow(20 * 60);

    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    $this->actingAs($admin)
        ->get(adminUrl('/feedback/'.Uuid::uuid7()->toString().'/screenshot'))
        ->assertRedirect('http://'.config('tenancy.central_domain').'/user/confirm-password');
});

it('redirects a guest away from a screenshot', function (): void {
    $this->get(adminUrl('/feedback/'.Uuid::uuid7()->toString().'/screenshot'))->assertRedirect();
});
