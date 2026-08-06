<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Both I3 tables are `(tenant_id, user_id, …)` shaped, and the isolation is split across two layers on
 * purpose: **RLS keeps a row inside its TENANT, and an application predicate keeps it private to its USER.**
 *
 * That split is the whole reason the migrations refuse the `belongs_to_user` variant — its policies carry no
 * tenant predicate at all, so a consultant in two tenants would read across both. So each half needs its own
 * proof: the tenant half must be shown to come from the DATABASE (the other tenant counts zero even with no
 * application code in the way), and the user half must be shown to come from the CODE (the raw table still
 * holds both rows, so `scopeForUser()` is load-bearing rather than decorative).
 */
beforeEach(function (): void {
    TenantContext::flush();
    $this->acme = inboxTenant('acme');
    $this->beta = inboxTenant('beta');
});

it('hides one tenant\'s notifications from another', function (): void {
    $user = User::factory()->create();

    enterTenant($this->acme->id, $user->id);
    Notification::factory()->create(['user_id' => $user->id]);

    expect(Notification::query()->count())->toBe(1);

    enterTenant($this->beta->id, $user->id);

    // The SAME person, in a tenant they are equally entitled to act in. RLS — not a scope, not a policy —
    // is what makes their Acme notifications invisible here.
    expect(Notification::query()->count())->toBe(0);
});

it('denies writing a notification into another tenant (WITH CHECK)', function (): void {
    $user = User::factory()->create();

    enterTenant($this->acme->id, $user->id);

    $rogue = new Notification;
    $rogue->forceFill([
        'tenant_id' => $this->beta->id, // deliberately not the context tenant
        'user_id' => $user->id,
        'type' => NotificationType::SubmissionReceived->value,
        'data' => [],
    ]);

    expect(fn () => $rogue->save())->toThrow(QueryException::class);
});

it('keeps one member\'s notifications out of another member\'s list in application code', function (): void {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    enterTenant($this->acme->id, $alice->id);
    Notification::factory()->create(['user_id' => $alice->id]);
    Notification::factory()->create(['user_id' => $bob->id]);

    expect(Notification::query()->forUser($alice)->count())->toBe(1)
        // …and the database is emphatically NOT doing it. Under `strict` both rows are visible to any
        // member of the tenant, which is exactly why every read path must apply the scope.
        ->and(Notification::query()->count())->toBe(2);
});

it('scopes the unread count to one person', function (): void {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    enterTenant($this->acme->id, $alice->id);
    Notification::factory()->create(['user_id' => $alice->id]);
    Notification::factory()->read()->create(['user_id' => $alice->id]);
    Notification::factory()->count(3)->create(['user_id' => $bob->id]);

    expect(Notification::query()->forUser($alice)->unread()->count())->toBe(1);
});

it('hides one tenant\'s preferences from another', function (): void {
    $user = User::factory()->create();

    enterTenant($this->acme->id, $user->id);
    NotificationPreference::factory()->silenced()->create(['user_id' => $user->id]);

    expect(NotificationPreference::query()->count())->toBe(1);

    enterTenant($this->beta->id, $user->id);

    expect(NotificationPreference::query()->count())->toBe(0);
});

it('lets one person hold different preferences for the same type in two tenants', function (): void {
    // §23's own sentence — "a user in multiple tenants may set different preferences per tenant" — and the
    // single most direct demonstration that the unique index has to include `tenant_id`, which is the same
    // reason the RLS variant has to be `strict`.
    $consultant = User::factory()->create();

    enterTenant($this->acme->id, $consultant->id);
    NotificationPreference::factory()->silenced()->create([
        'user_id' => $consultant->id,
        'notification_type' => NotificationType::SubmissionReceived,
    ]);

    enterTenant($this->beta->id, $consultant->id);
    NotificationPreference::factory()->create([
        'user_id' => $consultant->id,
        'notification_type' => NotificationType::SubmissionReceived,
    ]);

    expect(NotificationPreference::query()->count())->toBe(1)
        ->and(NotificationPreference::query()->first()?->email_enabled)->toBeTrue();

    enterTenant($this->acme->id, $consultant->id);

    expect(NotificationPreference::query()->first()?->email_enabled)->toBeFalse();
});

it('rejects a notification type outside the enum (CHECK)', function (): void {
    $user = User::factory()->create();

    enterTenant($this->acme->id, $user->id);

    // Raw insert on purpose: the model's enum cast rejects an unknown value in PHP before the database ever
    // sees it, which proves the cast and nothing else. The CHECK is generated from NotificationType::values()
    // at migration time, and this is the assertion that the generation actually happened rather than being a
    // sentence in a docblock.
    expect(fn () => DB::table('notifications')->insert([
        'id' => Str::uuid7()->toString(),
        'tenant_id' => $this->acme->id,
        'user_id' => $user->id,
        'type' => 'submission_teleported',
        'data' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
