<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\FeedbackStatus;
use App\Enums\ScanStatus;
use App\Models\Audit;
use App\Models\FeedbackReport;
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
