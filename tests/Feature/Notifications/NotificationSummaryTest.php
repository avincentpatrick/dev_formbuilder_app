<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Enums\SubmissionPdfOutcome;
use App\Enums\SubmissionStatus;
use App\Models\Notification;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Notifications\NotificationPresenter;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The notification centre's feed (Increment I4, PRD Feature #13b) — `GET /notifications`, the JSON sidecar
| the bell polls. Props are asserted KEY BY KEY rather than with a bare `assertJsonStructure`: a partial
| assertion passes against a payload that has silently lost half its shape, which on this surface means a
| bell that renders rows with no words or links that go nowhere.
|
| TRAPS, each already paid for once elsewhere:
|  · No `withoutVite()` anywhere in this file — the route returns JSON and renders no Inertia page.
|  · The tenant GUC dies with every HTTP request, so an RLS-invisible row reads as ABSENT, not as
|    unchanged. Re-`enterTenant()` before asserting on any row after a request.
|  · `tenant_users` is strict-RLS, so every membership is minted BEFORE the first request; a
|    `makeActiveMember()` between two calls fails the INSERT policy.
|  · Within ONE Pest test the container keeps the Tenant bound by the FIRST request (the stancl
|    early-return), so a cross-tenant assertion needs two tests.
|  · NEVER reach for `$user->unreadNotifications`. `User` uses `Notifiable`, the table name collides with
|    Laravel's own, and the accessor dies on `notifiable_type does not exist` in a way that reads like a
|    bug in this increment.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

function notificationFeedUrl(): string
{
    return 'http://acme.meridian.test/notifications';
}

it('returns the summary shape key by key', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner, 'Clinic Intake');
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);

    Notification::factory()->create([
        'user_id' => $this->owner->id,
        'type' => NotificationType::SubmissionReceived,
        'data' => [
            'submission_id' => (string) $submission->getKey(),
            'form_id' => (string) $form->getKey(),
            'form_title' => 'Clinic Intake',
        ],
    ]);

    $this->actingAs($this->owner)->getJson(notificationFeedUrl())->assertOk()
        ->assertJsonPath('unread_count', 1)
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.type', 'submission_received')
        ->assertJsonPath('items.0.title', 'New submission')
        ->assertJsonPath('items.0.description', 'A new response arrived on Clinic Intake.')
        ->assertJsonPath('items.0.url', '/submissions/'.$submission->getKey())
        ->assertJsonPath('items.0.action_label', 'View submission')
        ->assertJsonPath('items.0.read_at', null)
        ->assertJsonStructure(['unread_count', 'items' => [['id', 'type', 'title', 'description', 'url', 'action_label', 'read_at', 'created_at']]]);
});

it('returns only MY rows — RLS keeps the tenant, forUser keeps the person', function (): void {
    // Both memberships minted before the first request: `tenant_users` is strict-RLS and its INSERT policy
    // needs the GUC, which the request kills.
    $bob = User::factory()->create();
    makeActiveMember($bob, 'admin');

    Notification::factory()->count(2)->create(['user_id' => $this->owner->id]);
    Notification::factory()->count(2)->create(['user_id' => $bob->id]);

    $response = $this->actingAs($this->owner)->getJson(notificationFeedUrl())->assertOk();

    $response->assertJsonPath('unread_count', 2)->assertJsonCount(2, 'items');

    // …and the database is emphatically NOT doing it. Under `strict` all four rows are visible to any
    // member of the tenant, which is what makes `scopeForUser()` load-bearing rather than decorative.
    enterTenant($this->tenant->id, $this->owner->id);
    expect(Notification::query()->count())->toBe(4);
});

it('counts every unread row but returns at most one popover-full', function (): void {
    Notification::factory()->count(NotificationPresenter::SUMMARY_LIMIT + 5)
        ->create(['user_id' => $this->owner->id]);

    $this->actingAs($this->owner)->getJson(notificationFeedUrl())->assertOk()
        // The badge must show the TRUE total — a user with twenty unread sees twenty, not fifteen.
        ->assertJsonPath('unread_count', NotificationPresenter::SUMMARY_LIMIT + 5)
        ->assertJsonCount(NotificationPresenter::SUMMARY_LIMIT, 'items');
});

it('joins a leading slash onto the path the enum returns', function (): void {
    $endpoint = WebhookEndpoint::factory()->create();

    Notification::factory()->create([
        'user_id' => $this->owner->id,
        'type' => NotificationType::MemberInvited,
        'data' => ['email' => 'new@example.test', 'role' => 'viewer'],
    ]);
    Notification::factory()->create([
        'user_id' => $this->owner->id,
        'type' => NotificationType::WebhookFailed,
        'data' => [
            'webhook_endpoint_id' => (string) $endpoint->getKey(),
            'endpoint_name' => $endpoint->name,
            'failure_count' => 20,
        ],
    ]);

    assignUnlimitedPlan();

    $response = $this->actingAs($this->owner)->getJson(notificationFeedUrl())->assertOk();

    $urls = collect($response->json('items'))->pluck('url')->all();

    // Without the join `<Link href="members">` resolves RELATIVE to the current URL — correct on
    // /dashboard, silently wrong on /forms/{id}/builder, and identical in every screenshot.
    expect($urls)->toContain('/members')
        ->and($urls)->toContain('/webhooks/'.$endpoint->getKey());
});

it('returns a null url for a row whose payload names no destination', function (): void {
    Notification::factory()->create([
        'user_id' => $this->owner->id,
        'type' => NotificationType::ExportReady,
        // No `submission_id` at all — pathFor() answers null rather than inventing a link.
        'data' => ['form_title' => 'Clinic Intake', 'outcome' => SubmissionPdfOutcome::Ready->value],
    ]);

    $this->actingAs($this->owner)->getJson(notificationFeedUrl())->assertOk()
        ->assertJsonPath('items.0.url', null)
        // The action label still ships: the row renders it only when there is somewhere to go, but the
        // decision is the client's and the contract does not change shape per row.
        ->assertJsonPath('items.0.action_label', 'View submission');
});

it('returns a null url for a target that no longer exists', function (): void {
    Notification::factory()->create([
        'user_id' => $this->owner->id,
        'type' => NotificationType::SubmissionReceived,
        'data' => ['submission_id' => (string) Str::uuid(), 'form_title' => 'Clinic Intake'],
    ]);

    // A dead link would send the recipient to Laravel's bare 404, OUTSIDE the Inertia shell.
    $this->actingAs($this->owner)->getJson(notificationFeedUrl())->assertOk()
        ->assertJsonPath('items.0.url', null);
});

it('returns a null url for a submission the recipient may not open', function (): void {
    // A Form Editor is collaborator-scoped: they reach only forms they hold a grant on. This one holds
    // none, which is exactly the state a revoked grant leaves behind — and SubmissionPolicy::view() has no
    // respondent clause, so their own "your submission was returned" row would otherwise 403.
    $editor = User::factory()->create();
    makeActiveMember($editor, 'form_editor');

    $form = publishedInboxForm($this->tenant, $this->owner, 'Clinic Intake');
    $submission = seedInboxSubmission($form, $editor, SubmissionStatus::Returned, ['full_name' => 'Ada']);

    Notification::factory()->create([
        'user_id' => $editor->id,
        'type' => NotificationType::SubmissionReturned,
        'data' => ['submission_id' => (string) $submission->getKey(), 'form_title' => 'Clinic Intake'],
    ]);

    $this->actingAs($editor)->getJson(notificationFeedUrl())->assertOk()
        ->assertJsonPath('items.0.url', null)
        // The row still renders — it is their notification and it still tells them something true.
        ->assertJsonPath('items.0.title', 'Submission returned');
});

it('builds the in-app copy server-side, including the null form_title fallback', function (): void {
    Notification::factory()->create([
        'user_id' => $this->owner->id,
        'type' => NotificationType::SubmissionApproved,
        // The two review outcomes write `$form?->title`, so a trashed form yields null.
        'data' => ['submission_id' => (string) Str::uuid(), 'form_title' => null],
    ]);

    $this->actingAs($this->owner)->getJson(notificationFeedUrl())->assertOk()
        ->assertJsonPath('items.0.title', 'Submission approved')
        ->assertJsonPath('items.0.description', 'Your response to one of your forms was approved.');
});

it('does not tell someone an export is ready when it failed', function (): void {
    Notification::factory()->create([
        'user_id' => $this->owner->id,
        'type' => NotificationType::ExportReady,
        'data' => ['form_title' => 'Clinic Intake', 'outcome' => SubmissionPdfOutcome::QuotaExceeded->value],
    ]);

    // GeneratePdfJob records an export_ready row for EVERY terminal outcome, so before I4 a quota-blocked
    // export produced a bell row headed "Export ready" pointing at a submission with no artifact on it.
    $this->actingAs($this->owner)->getJson(notificationFeedUrl())->assertOk()
        ->assertJsonPath('items.0.title', 'Export failed')
        ->assertJsonPath('items.0.description', 'Your export for Clinic Intake could not be generated — the storage quota is full.');
});

it('orders newest first and reports a read row as read', function (): void {
    $older = Notification::factory()->create(['user_id' => $this->owner->id]);
    $older->forceFill(['created_at' => now()->subDays(3), 'read_at' => now()->subDays(3)])->saveQuietly();

    $newer = Notification::factory()->create(['user_id' => $this->owner->id]);
    $newer->forceFill(['created_at' => now()->subMinutes(5)])->saveQuietly();

    $this->actingAs($this->owner)->getJson(notificationFeedUrl())->assertOk()
        ->assertJsonPath('unread_count', 1)
        ->assertJsonPath('items.0.id', (string) $newer->getKey())
        ->assertJsonPath('items.0.read_at', null)
        ->assertJsonPath('items.1.id', (string) $older->getKey());

    expect($this->actingAs($this->owner)->getJson(notificationFeedUrl())->json('items.1.read_at'))->not->toBeNull();
});

it('answers 200 with an empty list for someone who has never been notified', function (): void {
    $this->actingAs($this->owner)->getJson(notificationFeedUrl())->assertOk()
        ->assertJsonPath('unread_count', 0)
        ->assertJsonPath('items', []);
});

it('refuses a guest', function (): void {
    $this->getJson(notificationFeedUrl())->assertUnauthorized();
});
