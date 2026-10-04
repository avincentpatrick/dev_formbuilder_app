<?php

declare(strict_types=1);

use App\Exceptions\Forms\PublishValidationException;
use App\Models\Form;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Forms\RedirectTarget;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M130 (`R-db169c29`, `D76`) — publish refuses an after-submit destination that is not one.
|--------------------------------------------------------------------------
| Every refusal follows a publish of the same form that succeeded with a working destination, so the refusal can
| only come from the one fact changed. Matched on the stable `code`, never the wording.
|
| ⚠️ Helpers are prefixed `redirectGate*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = guestTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    $this->form = guestForm($this->tenant, $this->owner, 'intake');
    $this->target = guestForm($this->tenant, $this->owner, 'follow-up');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** The publish refusal's codes, or [] when the publish went through. */
function redirectGatePublish(Form $form, User $owner): array
{
    try {
        app(PublishService::class)->publish($form->refresh(), $owner);

        return [];
    } catch (PublishValidationException $refusal) {
        return array_column($refusal->violations(), 'code');
    }
}

it('publishes a form whose destination respondents can open', function (): void {
    app(FormService::class)->setConfirmationMessage($this->form, null, null, RedirectTarget::form($this->target->id), $this->owner);
    expect(redirectGatePublish($this->form, $this->owner))->toBe([]);

    app(FormService::class)->setConfirmationMessage($this->form->refresh(), null, null, RedirectTarget::url('https://health.example.org/next'), $this->owner);
    expect(redirectGatePublish($this->form, $this->owner))->toBe([]);
});

it('refuses a destination form respondents cannot open, after the same publish succeeds with one they can', function (string $case): void {
    app(FormService::class)->setConfirmationMessage($this->form, null, null, RedirectTarget::form($this->target->id), $this->owner);
    expect(redirectGatePublish($this->form, $this->owner))->toBe([]);

    match ($case) {
        'guest access off' => $this->target->update(['allow_guest_submissions' => false]),
        'no public link' => $this->target->update(['public_slug' => null]),
        'in the bin' => $this->target->delete(),
    };

    expect(redirectGatePublish($this->form, $this->owner))->toBe(['redirect_target_unavailable']);
})->with(['guest access off', 'no public link', 'in the bin']);

it('refuses a stored address the redirect rule would refuse today', function (): void {
    app(FormService::class)->setConfirmationMessage($this->form, null, null, RedirectTarget::url('https://health.example.org/next'), $this->owner);
    expect(redirectGatePublish($this->form, $this->owner))->toBe([]);

    // Written past the request rule, as an older build or a direct edit could have left it.
    $this->form->refresh()->forceFill(['redirect_url' => 'http://health.example.org/next'])->save();

    expect(redirectGatePublish($this->form, $this->owner))->toBe(['redirect_url_invalid']);
});
