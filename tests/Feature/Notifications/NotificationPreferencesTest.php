<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The per-type notification preferences card on /settings (Increment I4, data-dictionary §23).
|
| The card's two invariants are both easy to break and both invisible if broken:
|  · EVERY WRITE CARRIES BOTH BOOLEANS. The columns default true/true, which disagrees with
|    `submission_received`'s email default of FALSE — so a partial write leaning on the column default
|    silently opts a user INTO the one type the PRD says to keep quiet.
|  · THE TWO SITE-OWNED TYPES IGNORE THE SUBMITTED EMAIL FLAG. `export_ready` and `webhook_failed` keep
|    their purpose-built branded emails, so the card renders that control as unavailable and the request
|    forces the value rather than storing something the resolver will ignore on read.
|
| TRAPS: `withoutVite()` on the /settings GET (it renders an Inertia page). The GUC dies with each
| request. A cross-tenant assertion needs TWO tests — within one, the container keeps the Tenant bound by
| the first request.
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

function settingsUrl(): string
{
    return 'http://acme.meridian.test/settings';
}

function notificationPreferenceUrl(): string
{
    return 'http://acme.meridian.test/settings/notifications';
}

it('renders all seven types with their resolved defaults', function (): void {
    $this->withoutVite();

    $this->actingAs($this->owner)->get(settingsUrl())->assertOk()->assertInertia(fn ($page) => $page
        ->component('Settings/Index', false)
        // Order is NotificationType::cases(), which NotificationTypeTest already pins as load-bearing for
        // the two generated CHECK constraints. This is its first human-visible consumer.
        ->has('notificationPreferences', count(NotificationType::cases()))
        ->where('notificationPreferences.0.type', 'submission_received')
        ->where('notificationPreferences.0.label', 'New submission')
        ->where('notificationPreferences.0.in_app', true)
        // The one type whose email default departs from the column default.
        ->where('notificationPreferences.0.email', false)
        ->where('notificationPreferences.0.email_locked', false)
        ->where('notificationPreferences.4.type', 'export_ready')
        ->where('notificationPreferences.4.email', true)
        ->where('notificationPreferences.4.email_locked', true)
        ->where('notificationPreferences.6.type', 'webhook_failed')
        ->where('notificationPreferences.6.email_locked', true)
    );
});

it('reflects a stored override rather than the platform default', function (): void {
    NotificationPreference::factory()->ofType(NotificationType::SubmissionReceived)->silenced()
        ->create(['user_id' => $this->owner->id]);

    $this->withoutVite();

    $this->actingAs($this->owner)->get(settingsUrl())->assertOk()->assertInertia(fn ($page) => $page
        ->where('notificationPreferences.0.in_app', false)
        ->where('notificationPreferences.0.email', false)
    );
});

it('persists BOTH booleans on a write', function (): void {
    $this->actingAs($this->owner)->from(settingsUrl())->patch(notificationPreferenceUrl(), [
        'notification_type' => NotificationType::SubmissionReceived->value,
        'in_app_enabled' => true,
        'email_enabled' => true,
    ])->assertRedirect();

    enterTenant($this->tenant->id, $this->owner->id);
    $row = NotificationPreference::query()->forUser($this->owner)->firstOrFail();

    // If `email_enabled` had been left to the column default this would still be true — which is why the
    // second half of the test flips both and asserts the write actually reaches the column.
    expect($row->in_app_enabled)->toBeTrue()->and($row->email_enabled)->toBeTrue();

    $this->actingAs($this->owner)->from(settingsUrl())->patch(notificationPreferenceUrl(), [
        'notification_type' => NotificationType::SubmissionReceived->value,
        'in_app_enabled' => false,
        'email_enabled' => false,
    ])->assertRedirect();

    enterTenant($this->tenant->id, $this->owner->id);
    expect($row->refresh()->in_app_enabled)->toBeFalse()->and($row->refresh()->email_enabled)->toBeFalse();
});

it('forces the email flag for a type whose email the emitting site owns', function (): void {
    foreach ([NotificationType::ExportReady, NotificationType::WebhookFailed] as $type) {
        $this->actingAs($this->owner)->from(settingsUrl())->patch(notificationPreferenceUrl(), [
            'notification_type' => $type->value,
            'in_app_enabled' => false,
            // A value the card never offers — it can only come from a stale tab or a hand-built request.
            'email_enabled' => false,
        ])->assertRedirect();

        enterTenant($this->tenant->id, $this->owner->id);
        $row = NotificationPreference::query()->forUser($this->owner)
            ->where('notification_type', $type->value)->firstOrFail();

        // Stored as the type's real default, not as the submitted lie: the resolver ignores this column
        // for these two types, so storing `false` would leave a value nothing believes.
        expect($row->in_app_enabled)->toBeFalse()->and($row->email_enabled)->toBeTrue();
    }
});

it('reports the locked types as emailed even after someone tries to silence them', function (): void {
    $this->actingAs($this->owner)->from(settingsUrl())->patch(notificationPreferenceUrl(), [
        'notification_type' => NotificationType::ExportReady->value,
        'in_app_enabled' => false,
        'email_enabled' => false,
    ])->assertRedirect();

    $this->withoutVite();

    $this->actingAs($this->owner)->get(settingsUrl())->assertOk()->assertInertia(fn ($page) => $page
        ->where('notificationPreferences.4.in_app', false)
        ->where('notificationPreferences.4.email', true)
        ->where('notificationPreferences.4.email_locked', true)
    );
});

it('updates the existing row rather than duplicating it', function (): void {
    foreach ([true, false, true] as $inApp) {
        $this->actingAs($this->owner)->from(settingsUrl())->patch(notificationPreferenceUrl(), [
            'notification_type' => NotificationType::SubmissionApproved->value,
            'in_app_enabled' => $inApp,
            'email_enabled' => true,
        ])->assertRedirect();
    }

    enterTenant($this->tenant->id, $this->owner->id);
    expect(NotificationPreference::query()->forUser($this->owner)->count())->toBe(1);
});

it('rejects a notification type outside the catalog', function (): void {
    $this->actingAs($this->owner)->from(settingsUrl())->patch(notificationPreferenceUrl(), [
        'notification_type' => 'submission_teleported',
        'in_app_enabled' => true,
        'email_enabled' => true,
    ])->assertSessionHasErrors('notification_type');
});

it('rejects a write that names only one channel', function (): void {
    // Both booleans are `required`, unlike the appearance write's all-`sometimes` axes.
    $this->actingAs($this->owner)->from(settingsUrl())->patch(notificationPreferenceUrl(), [
        'notification_type' => NotificationType::SubmissionReceived->value,
        'in_app_enabled' => true,
    ])->assertSessionHasErrors('email_enabled');
});

it('needs no permission — a Viewer sets their own preferences', function (): void {
    $viewer = User::factory()->create();
    makeActiveMember($viewer, 'viewer');

    $this->actingAs($viewer)->from(settingsUrl())->patch(notificationPreferenceUrl(), [
        'notification_type' => NotificationType::SubmissionReceived->value,
        'in_app_enabled' => false,
        'email_enabled' => false,
    ])->assertRedirect();

    enterTenant($this->tenant->id, $viewer->id);
    expect(NotificationPreference::query()->forUser($viewer)->count())->toBe(1);
});

it('writes into MY tenant only — the consultant case, first half', function (): void {
    $this->actingAs($this->owner)->from(settingsUrl())->patch(notificationPreferenceUrl(), [
        'notification_type' => NotificationType::SubmissionReceived->value,
        'in_app_enabled' => false,
        'email_enabled' => false,
    ])->assertRedirect();

    enterTenant($this->tenant->id, $this->owner->id);
    expect(NotificationPreference::query()->forUser($this->owner)->count())->toBe(1);
});

it('writes into MY tenant only — the consultant case, second half', function (): void {
    // A SEPARATE test, not a second request in the one above: within a single Pest test the container keeps
    // the Tenant bound by the first request (the stancl early-return), so a second host resolves nowhere.
    $beta = inboxTenant('beta');

    enterTenant($beta->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    NotificationPreference::factory()->ofType(NotificationType::SubmissionReceived)->silenced()
        ->create(['user_id' => $this->owner->id]);

    // Acme holds no row for this person, so the /settings read there must still show the platform default.
    enterTenant($this->tenant->id, $this->owner->id);
    expect(NotificationPreference::query()->forUser($this->owner)->count())->toBe(0);
});
