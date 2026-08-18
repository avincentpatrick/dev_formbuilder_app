<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Enums\TenantUserStatus;
use App\Events\MemberInvited;
use App\Models\Notification as NotificationRecord;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\TenantInvitationNotification;
use App\Services\Tenancy\TenantMembershipService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * The invite emission, and the one thing it must not do: replace or endanger the invitation email.
 */
beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    Notification::fake();

    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');

    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');
    enterTenant($this->tenant->id, $this->admin->id);

    $this->service = app(TenantMembershipService::class);
});

it('still sends the invitation email, and announces the invite alongside it', function (): void {
    Event::fake([MemberInvited::class]);

    $this->service->invite($this->tenant, 'newcomer@example.test', 'reviewer', $this->admin);

    // The invitee's email is the one message somebody is actually waiting on. It did not move behind the
    // event — the plaintext token dies with the local variable that built its URL.
    Notification::assertSentOnDemand(TenantInvitationNotification::class);

    Event::assertDispatched(
        MemberInvited::class,
        fn (MemberInvited $e): bool => $e->email === 'newcomer@example.test'
            && $e->role === 'reviewer'
            && $e->invitedByUserId === $this->admin->id,
    );
});

it('tells the other administrators, but not the person who did the inviting', function (): void {
    $this->service->invite($this->tenant, 'newcomer@example.test', 'reviewer', $this->admin);

    enterTenant($this->tenant->id, $this->admin->id);

    expect(NotificationRecord::query()->forUser($this->owner)->count())->toBe(1)
        ->and(NotificationRecord::query()->forUser($this->owner)->first()?->type)
        ->toBe(NotificationType::MemberInvited)
        ->and(NotificationRecord::query()->forUser($this->admin)->count())->toBe(0);
});

it('writes no in-app row for the invitee, who is not yet a member', function (): void {
    $this->service->invite($this->tenant, 'newcomer@example.test', 'reviewer', $this->admin);

    enterTenant($this->tenant->id, $this->admin->id);

    // Their id comes off `tenant_users` rather than off `users`: the users-visibility policy admits only
    // ACTIVE members, and an invitee's status is `invited` — so `User::where('email', …)` returns nothing
    // here, which is itself the reason an in-app row would be unreadable to them.
    $inviteeId = TenantUser::query()->where('user_id', '!=', $this->owner->id)
        ->where('status', TenantUserStatus::Invited)
        ->value('user_id');

    expect($inviteeId)->not->toBeNull()
        ->and(NotificationRecord::query()->where('user_id', $inviteeId)->count())->toBe(0);
});

it('never lets the invite token reach a notification payload', function (): void {
    $this->service->invite($this->tenant, 'newcomer@example.test', 'reviewer', $this->admin);

    $acceptUrl = null;
    Notification::assertSentOnDemand(
        TenantInvitationNotification::class,
        function (TenantInvitationNotification $notification) use (&$acceptUrl): bool {
            $acceptUrl = $notification->acceptUrl;

            return true;
        },
    );

    $token = basename((string) $acceptUrl);

    enterTenant($this->tenant->id, $this->admin->id);
    $payloads = NotificationRecord::query()->pluck('data')->map(
        static fn (array $data): string => json_encode($data, JSON_THROW_ON_ERROR)
    )->implode(' ');

    expect($token)->not->toBe('')
        ->and($payloads)->not->toContain($token);
});
