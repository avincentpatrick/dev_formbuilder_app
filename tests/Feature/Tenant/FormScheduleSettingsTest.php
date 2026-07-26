<?php

declare(strict_types=1);

use App\Enums\FormScheduleState;
use App\Models\Form;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\BuilderPresenter;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment H12a — the schedule config-write surface (PATCH /forms/{form}/schedule).
|--------------------------------------------------------------------------
| A guarded FormService::setSchedule write behind can:update,form (ungated — scheduled forms carry no plan
| feature). The controller recomputes schedule_state from the window; validation bounds the ordering, the IANA
| timezone and a positive cap.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function scheduleUrl(Form $form): string
{
    return "http://acme.meridian.test/forms/{$form->id}/schedule";
}

it('lets an editor set a form schedule + cap and derives schedule_state', function (): void {
    $form = makeForm($this->admin, 'Survey');

    $this->actingAs($this->admin)->withoutVite()
        ->patch(scheduleUrl($form), [
            'opens_at' => now()->addDay()->toIso8601String(),
            'closes_at' => now()->addWeek()->toIso8601String(),
            'timezone' => 'America/New_York',
            'max_responses' => 100,
        ])
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    $fresh = Form::findOrFail($form->id);
    expect($fresh->timezone)->toBe('America/New_York')
        ->and($fresh->max_responses)->toBe(100)
        ->and($fresh->opens_at)->not->toBeNull()
        ->and($fresh->closes_at)->not->toBeNull()
        ->and($fresh->schedule_state)->toBe(FormScheduleState::Scheduled); // opens_at is in the future
});

it('refuses a member without can:update,form (403)', function (): void {
    $form = makeForm($this->admin, 'Survey');

    $viewer = User::factory()->create();
    makeActiveMember($viewer, 'viewer');

    $this->actingAs($viewer)->withoutVite()
        ->patch(scheduleUrl($form), ['timezone' => 'UTC'])
        ->assertForbidden();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::findOrFail($form->id)->schedule_state)->toBeNull();
});

it('validates ordering, a real IANA timezone and a positive cap', function (): void {
    $form = makeForm($this->admin, 'Survey');

    // closes_at must be after opens_at
    $this->actingAs($this->admin)->withoutVite()
        ->patch(scheduleUrl($form), [
            'opens_at' => now()->addWeek()->toIso8601String(),
            'closes_at' => now()->addDay()->toIso8601String(),
            'timezone' => 'UTC',
        ])
        ->assertSessionHasErrors('closes_at');

    // an unknown timezone
    $this->actingAs($this->admin)->withoutVite()
        ->patch(scheduleUrl($form), ['timezone' => 'Not/AZone'])
        ->assertSessionHasErrors('timezone');

    // a non-positive cap
    $this->actingAs($this->admin)->withoutVite()
        ->patch(scheduleUrl($form), ['timezone' => 'UTC', 'max_responses' => 0])
        ->assertSessionHasErrors('max_responses');
});

it('clears a schedule back to null when the window is removed', function (): void {
    $form = makeForm($this->admin, 'Survey');
    $form->forceFill(['closes_at' => now()->addDay(), 'schedule_state' => FormScheduleState::Open])->save();

    $this->actingAs($this->admin)->withoutVite()
        ->patch(scheduleUrl($form), ['timezone' => 'UTC']) // no opens_at/closes_at
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    $fresh = Form::findOrFail($form->id);
    expect($fresh->closes_at)->toBeNull()
        ->and($fresh->schedule_state)->toBeNull(); // no window ⇒ no lifecycle
});

it('interprets a naive wall-clock in the submitted timezone (Increment H12b)', function (): void {
    $form = makeForm($this->admin, 'Survey');

    // The builder sends a naive datetime-local string + an IANA zone; 09:00 in Asia/Manila (UTC+8) is 01:00Z.
    $this->actingAs($this->admin)->withoutVite()
        ->patch(scheduleUrl($form), [
            'opens_at' => '2026-08-01T09:00',
            'closes_at' => '2026-08-01T17:00',
            'timezone' => 'Asia/Manila',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    enterTenant($this->tenant->id, $this->admin->id);
    $fresh = Form::findOrFail($form->id);
    expect($fresh->opens_at->utc()->format('Y-m-d H:i'))->toBe('2026-08-01 01:00')
        ->and($fresh->closes_at->utc()->format('Y-m-d H:i'))->toBe('2026-08-01 09:00');
});

it('surfaces raw schedule values + the timezone list to the builder (Increment H12b)', function (): void {
    $form = makeForm($this->admin, 'Survey');
    $form->forceFill([
        'opens_at' => now()->addDay(),
        'closes_at' => now()->addWeek(),
        'timezone' => 'Asia/Manila',
        'max_responses' => 250,
    ])->save();

    $props = app(BuilderPresenter::class)->present($form->refresh());

    expect($props['form']['timezone'])->toBe('Asia/Manila')
        ->and($props['form']['max_responses'])->toBe(250)
        ->and($props['form']['opens_at'])->not->toBeNull()
        ->and($props['form']['closes_at'])->not->toBeNull()
        ->and($props['timezones'])->toContain('Asia/Manila')
        ->and($props['timezones'])->toContain('UTC');
});
