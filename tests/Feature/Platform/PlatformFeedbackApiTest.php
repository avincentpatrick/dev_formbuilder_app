<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\FeedbackParty;
use App\Enums\FeedbackSource;
use App\Enums\FeedbackStatus;
use App\Enums\ScanStatus;
use App\Models\Audit;
use App\Models\FeedbackReport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M175 (`R-78f43f26`, `D139`) — the platform support API: what the dev session pulls and pushes back.
|--------------------------------------------------------------------------
| Three endpoints, and every one goes through `SuperAdminService` — the reads through the SELECT-only
| elevated carve-out the console already uses, the write through `transitionFeedback()` with the report's
| tenant context adopted. Nothing here widens the bypass. Every call leaves a PLATFORM audit row (no tenant)
| naming the token; a status change ALSO leaves the reporting workspace's own audit row, as the console does.
|
| The fixtures are committed (`consoleTenant()`, `consoleReport()`), for the reason FeedbackConsoleTest gives:
| the elevated read runs on its own connection and cannot see RefreshDatabase's open transaction.
*/

beforeEach(function (): void {
    // A platform audit row takes its tenant from the live context; a context left by an earlier test would
    // stamp a purged tenant on it. Production has none on this path.
    TenantContext::flush();
    $this->beforeApplicationDestroyed(purgeCommittedFeedbackFixtures(...));
});

/** @return list<array<string, mixed>> the platform audit rows a token left, newest last */
function platformAuditsFor(string $tokenId): array
{
    return DB::connection('pgsql_privileged')->table('audits')
        ->whereNull('tenant_id')
        ->where('auditable_type', 'platform_api_tokens')
        ->where('auditable_id', $tokenId)
        ->orderBy('id')
        ->get()
        ->map(fn ($row): array => (array) $row)
        ->all();
}

it('lists New and Ongoing reports across workspaces, newest first, with the kind, the page and the reporter', function (): void {
    [, $plain] = issueSupportToken();

    $alpha = consoleTenant('Console Alpha', 'console-alpha-'.Str::random(6));
    $beta = consoleTenant('Console Beta', 'console-beta-'.Str::random(6));
    $reporter = consoleUser(Str::random(8).'@feedbackconsoletest.local');
    $marker = 'Pull marker '.Str::random(10);

    consoleReport($alpha, $reporter, FeedbackStatus::New, "{$marker} new", [
        'type' => 'bug',
        'page_title' => 'Builder — Household survey',
        'page_url' => 'https://console-alpha.meridian.test/forms/1/builder',
        'reporter_role' => 'form_editor',
    ]);
    consoleReport($beta, $reporter, FeedbackStatus::Ongoing, "{$marker} ongoing", ['type' => 'suggestion']);
    consoleReport($beta, $reporter, FeedbackStatus::Resolved, "{$marker} resolved", ['type' => 'question']);

    $rows = collect($this->withToken($plain)
        ->getJson(platformApi(workspaceHost(), '/feedback?status=new,reviewed&per_page=100'))
        ->assertOk()
        ->json('data'))->filter(fn (array $row): bool => str_starts_with($row['remarks'], $marker))->keyBy('remarks');

    expect($rows->keys()->sort()->values()->all())->toBe(["{$marker} new", "{$marker} ongoing"])
        ->and($rows["{$marker} new"])->toMatchArray([
            'type' => 'bug',
            'type_label' => 'Bug',
            'status' => 'new',
            'status_label' => 'New',
            'page_title' => 'Builder — Household survey',
            'page_url' => 'https://console-alpha.meridian.test/forms/1/builder',
            'tenant_name' => 'Console Alpha',
            'reporter_role_label' => 'Form Editor',
        ])
        ->and($rows["{$marker} new"]['reporter']['email'])->toBe($reporter->email)
        ->and($rows["{$marker} ongoing"]['status_label'])->toBe('Ongoing');
});

it('narrows by kind and by when a report was sent, and refuses a status it does not know', function (): void {
    [, $plain] = issueSupportToken();

    $tenant = consoleTenant('Console Since', 'console-since-'.Str::random(6));
    $reporter = consoleUser(Str::random(8).'@feedbackconsoletest.local');
    $marker = 'Since marker '.Str::random(10);
    $old = consoleReport($tenant, $reporter, FeedbackStatus::New, "{$marker} old", ['type' => 'bug']);
    DB::connection('pgsql_privileged')->table('feedback_reports')->where('id', $old->id)->update(['submitted_at' => now()->subDays(10)]);
    consoleReport($tenant, $reporter, FeedbackStatus::New, "{$marker} recent bug", ['type' => 'bug']);
    consoleReport($tenant, $reporter, FeedbackStatus::New, "{$marker} recent question", ['type' => 'question']);

    $since = now()->subDays(2)->toIso8601String();
    $remarks = collect($this->withToken($plain)
        ->getJson(platformApi(workspaceHost(), '/feedback?type=bug&since='.urlencode($since).'&per_page=100'))
        ->assertOk()
        ->json('data'))->pluck('remarks')->filter(fn (string $r): bool => str_starts_with($r, $marker))->values()->all();

    expect($remarks)->toBe(["{$marker} recent bug"]);

    $this->withToken($plain)->getJson(platformApi(workspaceHost(), '/feedback?status=archived'))
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});

it('downloads a report\'s screenshot through the borrowed tenant context', function (): void {
    [, $plain] = issueSupportToken();

    $tenant = consoleTenant('Console Shot', 'console-shot-'.Str::random(6));
    $reporter = consoleUser(Str::random(8).'@feedbackconsoletest.local');
    $report = consoleReport($tenant, $reporter, FeedbackStatus::New, 'With a picture '.Str::random(8));
    consoleScreenshot($report, ScanStatus::Clean);

    $response = $this->withToken($plain)->get(platformApi(workspaceHost(), "/feedback/{$report->id}/screenshot"));

    $response->assertOk();
    expect($response->streamedContent())->toBe(CONSOLE_SCREENSHOT_BYTES);

    $this->withToken($plain)->getJson(platformApi(workspaceHost(), '/feedback/'.Uuid::uuid7()->toString().'/screenshot'))
        ->assertNotFound()->assertJsonPath('error.code', 'not_found');
});

it('moves a report with a reply, leaving the workspace\'s audit row and a platform row naming the token', function (): void {
    Notification::fake();
    [$token, $plain] = issueSupportToken();

    $tenant = consoleTenant('Console Push', 'console-push-'.Str::random(6));
    $reporter = consoleUser(Str::random(8).'@feedbackconsoletest.local');
    consoleMember($tenant, $reporter);
    $report = consoleReport($tenant, $reporter, FeedbackStatus::New, 'Push me '.Str::random(8));

    $this->withToken($plain)
        ->patchJson(platformApi(workspaceHost(), "/feedback/{$report->id}"), [
            'status' => 'resolved',
            'note' => 'Fixed in version abc1234, live on staging.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'resolved')
        ->assertJsonPath('data.resolution_note', 'Fixed in version abc1234, live on staging.');

    enterTenant($tenant->id, $reporter->id);
    expect(FeedbackReport::query()->findOrFail($report->id)->status)->toBe(FeedbackStatus::Resolved);

    /** @var Audit $tenantAudit */
    $tenantAudit = Audit::query()->where('auditable_type', 'feedback_reports')->where('auditable_id', $report->id)->firstOrFail();
    expect($tenantAudit->tenant_id)->toBe($tenant->id)
        ->and($tenantAudit->user_id)->toBe($token->user_id);

    $platform = platformAuditsFor($token->id);
    expect(collect($platform)->pluck('event')->all())->toContain(AuditEvent::Updated->value);
});

it('answers a step the lifecycle does not offer with a 422 in the envelope, never a 500', function (): void {
    [, $plain] = issueSupportToken();

    $tenant = consoleTenant('Console Refuse', 'console-refuse-'.Str::random(6));
    $reporter = consoleUser(Str::random(8).'@feedbackconsoletest.local');
    $report = consoleReport($tenant, $reporter, FeedbackStatus::Resolved, 'Refuse me '.Str::random(8));

    $this->withToken($plain)
        ->patchJson(platformApi(workspaceHost(), "/feedback/{$report->id}"), ['status' => 'wont_fix'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_transition');

    $this->withToken($plain)
        ->patchJson(platformApi(workspaceHost(), '/feedback/'.Uuid::uuid7()->toString()), ['status' => 'reviewed'])
        ->assertNotFound()->assertJsonPath('error.code', 'not_found');
});

it('audits every read as a platform row naming the token', function (): void {
    [$token, $plain] = issueSupportToken();

    $this->withToken($plain)->getJson(platformApi(workspaceHost()))->assertOk();
    $this->withToken($plain)->getJson(platformApi(workspaceHost(), '/feedback?status=new'))->assertOk();

    $platform = platformAuditsFor($token->id);
    expect(collect($platform)->where('event', AuditEvent::Exported->value)->count())->toBe(2)
        ->and(collect($platform)->last()['user_id'])->toBe($token->user_id);
});

/*
|--------------------------------------------------------------------------
| M179 (`R-e727ca94`, `D150`, `D151`; ADR-0023 §D7) — the thread, the relay, and the support team's reply.
|--------------------------------------------------------------------------
| The list now carries where a report came from, its reference, who confirmed it and its thread, and narrows by
| source, by reference, and by MEMBER comments since a time — so the pull can show a reopen or a tester's comment
| without our own replies coming back as news. Two writes join the one status change, under the same single
| ability: the relay (create or update by reference, never rewriting a status, telling no one) and a reply.
*/

it('carries the thread and where a report came from, and narrows by source, reference and new member comments', function (): void {
    [, $plain] = issueSupportToken();

    $tenant = consoleTenant('Console Thread API', 'console-thread-api-'.Str::random(6));
    $owner = consoleUser(Str::random(8).'@feedbackconsoletest.local');
    $tester = consoleUser(Str::random(8).'@feedbackconsoletest.local');
    consoleMember($tenant, $owner);
    consoleMember($tenant, $tester);

    $ref = 'U3-'.Str::random(6);
    $relayed = consoleReport($tenant, $owner, FeedbackStatus::Resolved, 'Relayed '.Str::random(8), [
        'source' => FeedbackSource::Relayed->value, 'reference' => $ref, 'title' => 'Larger label box', 'source_label' => 'Your items, Oct 16',
    ]);
    $button = consoleReport($tenant, $owner, FeedbackStatus::New, 'Button '.Str::random(8));
    $repliedOnly = consoleReport($tenant, $owner, FeedbackStatus::Ongoing, 'Replied only '.Str::random(8));

    consoleComment($relayed, $tester, FeedbackParty::Member, 'Still cut off on the tablet.');
    consoleComment($relayed, null, FeedbackParty::Support, 'Which tablet?');
    consoleComment($button, $owner, FeedbackParty::Member, 'An old note.', at: now()->subDays(5));
    consoleComment($repliedOnly, null, FeedbackParty::Support, 'Only we have written here.');

    $rows = collect($this->withToken($plain)->getJson(platformApi(workspaceHost(), '/feedback?source=relayed&per_page=100'))
        ->assertOk()->json('data'))->keyBy('id');

    expect($rows->has($relayed->id))->toBeTrue()
        ->and($rows->has($button->id))->toBeFalse()
        ->and($rows[$relayed->id])->toMatchArray(['source' => 'relayed', 'reference' => $ref, 'title' => 'Larger label box', 'source_label' => 'Your items, Oct 16'])
        ->and(array_column($rows[$relayed->id]['comments'], 'author_kind'))->toBe(['member', 'support'])
        ->and($rows[$relayed->id]['comments'][0]['author']['email'])->toBe($tester->email)
        ->and($rows[$relayed->id]['comments'][1]['author'])->toBeNull()
        ->and($rows[$relayed->id]['comments'][0]['body'])->toBe('Still cut off on the tablet.');

    $byReference = $this->withToken($plain)->getJson(platformApi(workspaceHost(), '/feedback?reference='.$ref))->assertOk()->json('data');
    expect(array_column($byReference, 'id'))->toBe([$relayed->id]);

    $since = now()->subDay()->toIso8601String();
    $commented = collect($this->withToken($plain)->getJson(platformApi(workspaceHost(), '/feedback?per_page=100&commented_since='.urlencode($since)))
        ->assertOk()->json('data'))->pluck('id');
    expect($commented->contains($relayed->id))->toBeTrue()
        ->and($commented->contains($button->id))->toBeFalse()
        ->and($commented->contains($repliedOnly->id))->toBeFalse();
});

it('relays testing-round comments by reference: creates once, updates the words, never rewrites a status, tells no one', function (): void {
    Notification::fake();
    [$token, $plain] = issueSupportToken();

    $slug = 'console-relay-'.Str::random(6);
    $tenant = consoleTenant('Console Relay', $slug);
    $owner = consoleUser(Str::random(8).'@feedbackconsoletest.local');
    consoleMember($tenant, $owner);
    $suffix = Str::random(5);

    $items = [
        ['reference' => "U1-{$suffix}", 'title' => 'Allow negative is a rule', 'remarks' => 'The original comment.', 'source_label' => 'Your items, Oct 16', 'status' => 'resolved', 'note' => 'Fixed in version a5b718b, live on staging.'],
        ['reference' => "R1-4-{$suffix}", 'title' => 'The key has already been taken', 'remarks' => 'The original comment.', 'source_label' => "Testers' round 1, Oct 15", 'status' => 'confirmed', 'note' => 'Fixed Oct 15.', 'confirmed_at' => '2026-10-15T09:00:00+08:00'],
        ['reference' => "U8-{$suffix}", 'title' => 'Calculations by clicking', 'remarks' => 'The original comment.', 'source_label' => 'Your items, Oct 16', 'status' => 'reviewed', 'note' => 'Planned for batch 3.'],
    ];

    $first = $this->withToken($plain)->postJson(platformApi(workspaceHost(), '/feedback/relayed'), [
        'tenant' => $slug, 'reporter_email' => $owner->email, 'items' => $items,
    ])->assertOk()->json('data');

    expect(array_column($first, 'outcome'))->toBe(['created', 'created', 'created']);

    enterTenant($tenant->id, $owner->id);

    $stored = DB::table('feedback_reports')->where('source', 'relayed')->get()->keyBy('reference');
    expect($stored)->toHaveCount(3)
        ->and($stored["U1-{$suffix}"]->status)->toBe('resolved')
        ->and($stored["U1-{$suffix}"]->resolution_note)->toBe('Fixed in version a5b718b, live on staging.')
        ->and($stored["U1-{$suffix}"]->resolved_at)->not->toBeNull()
        ->and($stored["U1-{$suffix}"]->user_id)->toBe($owner->id)
        ->and($stored["R1-4-{$suffix}"]->status)->toBe('confirmed')
        ->and($stored["R1-4-{$suffix}"]->confirmed_by)->toBe($owner->id)
        ->and($stored["U8-{$suffix}"]->status)->toBe('reviewed')
        ->and($stored["U8-{$suffix}"]->resolved_at)->toBeNull();

    // A second run: the words of one item changed, and its status in the file too — the status must hold.
    $items[0]['title'] = 'Allow negative is now a validation rule';
    $items[0]['status'] = 'reviewed';
    $second = $this->withToken($plain)->postJson(platformApi(workspaceHost(), '/feedback/relayed'), [
        'tenant' => $slug, 'reporter_email' => $owner->email, 'items' => $items,
    ])->assertOk()->json('data');

    enterTenant($tenant->id, $owner->id);
    expect(array_column($second, 'outcome'))->toBe(['updated', 'unchanged', 'unchanged'])
        ->and(DB::table('feedback_reports')->where('source', 'relayed')->count())->toBe(3);

    $u1 = DB::table('feedback_reports')->where('reference', "U1-{$suffix}")->first();
    expect($u1->title)->toBe('Allow negative is now a validation rule')
        ->and($u1->status)->toBe('resolved')
        ->and(DB::table('notifications')->count())->toBe(0)
        ->and(collect(platformAuditsFor((string) $token->getKey()))->pluck('event')->all())->toContain(AuditEvent::Created->value)
        ->and(DB::table('audits')->where('tenant_id', $tenant->id)->where('auditable_type', 'feedback_reports')->count())->toBe(4);
});

it('refuses to relay into an unknown workspace or for someone who is not a member of it', function (): void {
    [, $plain] = issueSupportToken();

    $slug = 'console-relay-refuse-'.Str::random(6);
    consoleTenant('Console Relay Refuse', $slug);
    $stranger = consoleUser(Str::random(8).'@feedbackconsoletest.local');
    $item = ['reference' => 'U1-'.Str::random(5), 'title' => 'T', 'remarks' => 'R', 'source_label' => 'S'];

    $this->withToken($plain)->postJson(platformApi(workspaceHost(), '/feedback/relayed'), [
        'tenant' => 'no-such-workspace-'.Str::random(6), 'reporter_email' => $stranger->email, 'items' => [$item],
    ])->assertStatus(422)->assertJsonPath('error.code', 'unknown_tenant');

    $this->withToken($plain)->postJson(platformApi(workspaceHost(), '/feedback/relayed'), [
        'tenant' => $slug, 'reporter_email' => $stranger->email, 'items' => [$item],
    ])->assertStatus(422)->assertJsonPath('error.code', 'unknown_reporter');

    // No token at all — `withToken()` persists for the rest of a test, so it is dropped explicitly.
    $this->withoutToken()->postJson(platformApi(workspaceHost(), '/feedback/relayed'), ['tenant' => $slug, 'items' => [$item]])->assertUnauthorized();
});

it('posts the support team\'s reply to a report\'s thread, and audits it in both ledgers', function (): void {
    Notification::fake();
    [$token, $plain] = issueSupportToken();

    $tenant = consoleTenant('Console Reply API', 'console-reply-api-'.Str::random(6));
    $reporter = consoleUser(Str::random(8).'@feedbackconsoletest.local');
    consoleMember($tenant, $reporter);
    $report = consoleReport($tenant, $reporter, FeedbackStatus::Ongoing, 'Reply via the API '.Str::random(8));

    $this->withToken($plain)->postJson(platformApi(workspaceHost(), "/feedback/{$report->id}/comments"), ['body' => ''])
        ->assertStatus(422);

    $reply = $this->withToken($plain)->postJson(platformApi(workspaceHost(), "/feedback/{$report->id}/comments"), ['body' => 'Which tablet?'])
        ->assertOk()->json('data');

    enterTenant($tenant->id, $reporter->id);
    expect($reply['author_kind'])->toBe('support')
        ->and($reply['body'])->toBe('Which tablet?')
        ->and(DB::table('feedback_comments')->where('feedback_report_id', $report->id)->where('author_kind', 'support')->count())->toBe(1)
        ->and(DB::table('audits')->where('tenant_id', $tenant->id)->where('auditable_type', 'feedback_comments')->count())->toBe(1)
        ->and(collect(platformAuditsFor((string) $token->getKey()))->pluck('new_values')->implode(' '))->toContain('feedback.reply');

    $this->withToken($plain)->postJson(platformApi(workspaceHost(), '/feedback/'.Uuid::uuid7()->toString().'/comments'), ['body' => 'Hello'])
        ->assertNotFound();
});

it('accepts the committed relay file whole — 31 created, then 31 unchanged (M179, D150)', function (): void {
    Notification::fake();
    [, $plain] = issueSupportToken();

    $slug = 'console-relay-file-'.Str::random(6);
    $tenant = consoleTenant('Console Relay File', $slug);
    $owner = consoleUser(Str::random(8).'@feedbackconsoletest.local');
    consoleMember($tenant, $owner);

    $items = json_decode((string) file_get_contents(base_path('database/data/feedback/relayed-2026-10.json')), true, flags: JSON_THROW_ON_ERROR)['items'];

    $first = $this->withToken($plain)->postJson(platformApi(workspaceHost(), '/feedback/relayed'), [
        'tenant' => $slug, 'reporter_email' => $owner->email, 'items' => $items,
    ])->assertOk()->json('data');
    $second = $this->withToken($plain)->postJson(platformApi(workspaceHost(), '/feedback/relayed'), [
        'tenant' => $slug, 'reporter_email' => $owner->email, 'items' => $items,
    ])->assertOk()->json('data');

    expect(array_count_values(array_column($first, 'outcome')))->toBe(['created' => 31])
        ->and(array_count_values(array_column($second, 'outcome')))->toBe(['unchanged' => 31]);
});
