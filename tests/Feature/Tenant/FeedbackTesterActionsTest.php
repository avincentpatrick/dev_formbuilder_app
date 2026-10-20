<?php

declare(strict_types=1);

use App\Enums\AttachmentKind;
use App\Enums\AuditEvent;
use App\Enums\FeedbackCommentAction;
use App\Enums\FeedbackParty;
use App\Enums\FeedbackSource;
use App\Enums\FeedbackStatus;
use App\Enums\FeedbackType;
use App\Models\Attachment;
use App\Models\Audit;
use App\Models\FeedbackComment;
use App\Models\FeedbackReport;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M179 (`R-129c44c3`, `D151`) — the testers' door: It works, Still a problem, and a comment.
|--------------------------------------------------------------------------
| The user (2026-10-19): *"we can only tag those as resolved on our end but users can comment or feedback again
| about the output of our fix"*. So on a report the support team marked Resolved, a tester presses **It works**
| (→ Confirmed working, with their name) or **Still a problem** (→ Ongoing again, and a comment is required);
| anyone who may see a report may comment on it at any time; only the support team marks Resolved.
|
| WHO MAY ACT is the reports' visibility, unchanged in spirit: the reporter on their own report, any member on a
| RELAYED report (every member sees those — `D150`), and Owner/Admin (`feedback.view`) on any. A colleague's
| button report stays theirs: a Viewer may neither read nor touch it.
*/

beforeEach(function (): void {
    $this->withoutVite();
    Notification::fake();
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->owner = User::factory()->create();
    $this->tester = User::factory()->create();
    $this->colleague = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    makeActiveMember($this->tester, 'viewer');
    makeActiveMember($this->colleague, 'form_editor');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** A 1×1 PNG with real bytes — the storage service sniffs the content (see FeedbackTest's note on GD). */
function threadScreenshotFile(): UploadedFile
{
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

    return UploadedFile::fake()->createWithContent('still-broken.png', $png);
}

/** A report in the current workspace, in `$status`; `$relayed` makes it a testing-round comment. */
function threadReport(User $reporter, FeedbackStatus $status, bool $relayed = false): FeedbackReport
{
    $report = FeedbackReport::create([
        'user_id' => $reporter->id,
        'type' => FeedbackType::Bug,
        'route' => '/forms',
        'remarks' => 'The label box is too small.',
        'status' => $status,
    ]);

    $stamps = in_array($status, [FeedbackStatus::Resolved, FeedbackStatus::Confirmed], true)
        ? ['resolved_at' => now()->subDay(), 'resolved_by' => $reporter->id]
        : [];

    $report->forceFill([
        ...$stamps,
        ...($relayed ? ['source' => FeedbackSource::Relayed, 'reference' => 'U3-'.Str::random(4), 'title' => 'Larger label box'] : []),
    ])->save();

    return $report->refresh();
}

function threadUrl(FeedbackReport $report, string $verb): string
{
    return "http://acme.meridian.test/feedback/{$report->id}/{$verb}";
}

/**
 * Back into the workspace for the test's own reads. A request leaves the tenant context cleared on its way out
 * (the `FeedbackTest` precedent), so a read after it would see nothing under RLS.
 */
function threadBack(): void
{
    enterTenant(test()->tenant->id, test()->owner->id);
}

it('confirms a Resolved fix with It works: Confirmed working, the tester named, the fixer kept, the thread told', function (): void {
    $report = threadReport($this->tester, FeedbackStatus::Resolved);
    $resolvedBy = $report->resolved_by;

    $this->actingAs($this->tester)->post(threadUrl($report, 'confirm'))->assertRedirect()->assertSessionHasNoErrors();
    threadBack();

    $report->refresh();
    expect($report->status)->toBe(FeedbackStatus::Confirmed)
        ->and($report->confirmed_by)->toBe($this->tester->id)
        ->and($report->confirmed_at)->not->toBeNull()
        ->and($report->resolved_by)->toBe($resolvedBy)
        ->and($report->last_comment_at)->not->toBeNull();

    /** @var FeedbackComment $comment */
    $comment = FeedbackComment::query()->where('feedback_report_id', $report->id)->sole();
    expect($comment->action)->toBe(FeedbackCommentAction::Confirmed)
        ->and($comment->author_kind)->toBe(FeedbackParty::Member)
        ->and($comment->user_id)->toBe($this->tester->id);

    expect(Audit::withoutTenantScope()->where('auditable_id', $report->id)->where('event', AuditEvent::Updated->value)->count())->toBe(1);
});

it('refuses Still a problem without a comment, and moves nothing', function (): void {
    $report = threadReport($this->tester, FeedbackStatus::Resolved);

    $this->actingAs($this->tester)->post(threadUrl($report, 'reopen'), ['body' => '   '])
        ->assertRedirect()->assertSessionHasErrors('body');
    threadBack();

    expect($report->fresh()->status)->toBe(FeedbackStatus::Resolved)
        ->and(FeedbackComment::query()->count())->toBe(0);
});

it('sends a Resolved report back to Ongoing with Still a problem, the comment and its picture in the thread', function (): void {
    Storage::fake('local');
    $report = threadReport($this->tester, FeedbackStatus::Resolved);

    $this->actingAs($this->tester)->post(threadUrl($report, 'reopen'), [
        'body' => 'The box is bigger, but it still cuts the last line.',
        'screenshot' => threadScreenshotFile(),
    ])->assertRedirect()->assertSessionHasNoErrors();
    threadBack();

    $report->refresh();
    expect($report->status)->toBe(FeedbackStatus::Ongoing)
        ->and($report->resolved_at)->toBeNull()
        ->and($report->resolved_by)->toBeNull()
        ->and($report->confirmed_by)->toBeNull();

    /** @var FeedbackComment $comment */
    $comment = FeedbackComment::query()->where('feedback_report_id', $report->id)->sole();
    expect($comment->action)->toBe(FeedbackCommentAction::Reopened)
        ->and($comment->body)->toBe('The box is bigger, but it still cuts the last line.')
        ->and($comment->screenshot_attachment_id)->not->toBeNull();

    /** @var Attachment $shot */
    $shot = Attachment::query()->findOrFail($comment->screenshot_attachment_id);
    expect($shot->kind)->toBe(AttachmentKind::FeedbackScreenshot)
        ->and($shot->is_pii)->toBeTrue()
        ->and($shot->attachable_id)->toBe($report->id);
});

it('lets a tester take back a confirmation with Still a problem', function (): void {
    $report = threadReport($this->tester, FeedbackStatus::Confirmed);
    $report->forceFill(['confirmed_at' => now(), 'confirmed_by' => $this->tester->id])->save();

    $this->actingAs($this->tester)->post(threadUrl($report, 'reopen'), ['body' => 'It broke again after the update.'])
        ->assertRedirect()->assertSessionHasNoErrors();
    threadBack();

    expect($report->fresh()->status)->toBe(FeedbackStatus::Ongoing)
        ->and($report->fresh()->confirmed_at)->toBeNull();
});

it('offers It works only after a fix, and only once', function (): void {
    $ongoing = threadReport($this->tester, FeedbackStatus::Ongoing);
    $this->actingAs($this->tester)->post(threadUrl($ongoing, 'confirm'))->assertRedirect()->assertSessionHasErrors('feedback');
    threadBack();
    expect($ongoing->fresh()->status)->toBe(FeedbackStatus::Ongoing);

    $declined = threadReport($this->tester, FeedbackStatus::Declined);
    $this->actingAs($this->tester)->post(threadUrl($declined, 'reopen'), ['body' => 'Please reconsider.'])
        ->assertRedirect()->assertSessionHasErrors('feedback');
    threadBack();
    expect($declined->fresh()->status)->toBe(FeedbackStatus::Declined);

    $confirmed = threadReport($this->tester, FeedbackStatus::Confirmed);
    $this->actingAs($this->tester)->post(threadUrl($confirmed, 'confirm'))->assertRedirect()->assertSessionHasErrors('feedback');
    threadBack();
});

it('lets the reporter, any member on a relayed report, and Owner/Admin act — and no colleague on a button report', function (): void {
    $buttonReport = threadReport($this->tester, FeedbackStatus::Resolved);
    $relayed = threadReport($this->owner, FeedbackStatus::Resolved, relayed: true);
    $ownersOwn = threadReport($this->colleague, FeedbackStatus::Resolved);

    // A colleague cannot touch the tester's own button report.
    $this->actingAs($this->colleague)->post(threadUrl($buttonReport, 'comments'), ['body' => 'Me too.'])->assertForbidden();
    threadBack();
    $this->actingAs($this->colleague)->post(threadUrl($buttonReport, 'confirm'))->assertForbidden();
    threadBack();

    // Any member may act on a relayed report — the testers check the relayed fixes.
    $this->actingAs($this->tester)->post(threadUrl($relayed, 'confirm'))->assertRedirect()->assertSessionHasNoErrors();
    threadBack();
    expect($relayed->fresh()->confirmed_by)->toBe($this->tester->id);

    // Owner/Admin may act on any report in the workspace.
    $this->actingAs($this->owner)->post(threadUrl($ownersOwn, 'comments'), ['body' => 'Seen.'])->assertRedirect()->assertSessionHasNoErrors();
    threadBack();
});

it('does not reach another workspace\'s report at all', function (): void {
    $other = Tenant::create(['name' => 'Globex', 'slug' => 'globex']);
    $stranger = User::factory()->create();
    enterTenant($other->id, $stranger->id);
    makeActiveMember($stranger, 'owner');
    $foreign = threadReport($stranger, FeedbackStatus::Resolved, relayed: true);

    enterTenant($this->tenant->id, $this->owner->id);

    $this->actingAs($this->owner)->post(threadUrl($foreign, 'confirm'))->assertNotFound();
    threadBack();
});

it('takes a comment on any status, stamps the thread, and refuses an empty or overlong one', function (): void {
    $report = threadReport($this->tester, FeedbackStatus::Ongoing);

    $this->actingAs($this->tester)->post(threadUrl($report, 'comments'), ['body' => 'It happens on the tablet too.'])
        ->assertRedirect()->assertSessionHasNoErrors();
    threadBack();

    expect($report->fresh()->status)->toBe(FeedbackStatus::Ongoing)
        ->and($report->fresh()->last_comment_at)->not->toBeNull()
        ->and(FeedbackComment::query()->where('feedback_report_id', $report->id)->sole()->action)->toBeNull();

    $this->actingAs($this->tester)->post(threadUrl($report, 'comments'), ['body' => ''])->assertSessionHasErrors('body');
    threadBack();
    $this->actingAs($this->tester)->post(threadUrl($report, 'comments'), ['body' => str_repeat('a', 2001)])->assertSessionHasErrors('body');
    threadBack();
});

it('serves a comment\'s screenshot under feedback.view only, as every feedback screenshot is (ADR-0015 §D9)', function (): void {
    Storage::fake('local');
    $report = threadReport($this->tester, FeedbackStatus::Resolved, relayed: true);

    $this->actingAs($this->tester)->post(threadUrl($report, 'reopen'), [
        'body' => 'Still cut off.',
        'screenshot' => threadScreenshotFile(),
    ])->assertSessionHasNoErrors();
    threadBack();

    enterTenant($this->tenant->id, $this->owner->id);
    /** @var FeedbackComment $comment */
    $comment = FeedbackComment::query()->where('feedback_report_id', $report->id)->sole();
    Attachment::query()->whereKey($comment->screenshot_attachment_id)->update(['virus_scan_status' => 'clean']);

    $url = "http://acme.meridian.test/feedback/{$report->id}/comments/{$comment->id}/screenshot";

    $this->actingAs($this->tester)->get($url)->assertForbidden();
    threadBack();
    $this->actingAs($this->owner)->get($url)->assertOk();
    threadBack();
});
