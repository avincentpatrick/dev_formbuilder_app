<?php

declare(strict_types=1);

use App\Models\Form;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Forms\GuestReachability;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M130 (`R-db169c29`, `D76`) — one reachability predicate, held to the public link it describes.
|--------------------------------------------------------------------------
| `GuestReachability::reachable()` decides where a respondent may be sent after submitting, whether a destination
| may be published, and what the settings panel warns about. Each case below drives the REAL `/f/{slug}` link
| beside the predicate, so the day the route changes what it answers, this file says the predicate is behind.
|
| ⚠️ AN ARCHIVED FORM IS REACHABLE, AND THAT IS THE ROUTE'S ANSWER TODAY, NOT AN ENDORSEMENT: archiving leaves the
| public link working (filed by M130 as a row). The predicate mirrors the route; the row decides the route.
|
| ⚠️ Helpers are prefixed `reachability*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = guestTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** A form at `/f/intake` in the named state. */
function reachabilityForm(string $case, mixed $tenant, User $owner): Form
{
    if ($case === 'never published') {
        $form = app(FormService::class)->create($tenant, $owner, 'Draft only');
        $form->update(['public_slug' => 'intake', 'allow_guest_submissions' => true]);

        return $form->refresh();
    }

    $form = guestForm($tenant, $owner, 'intake');

    match ($case) {
        'open' => null,
        'guest access off' => $form->update(['allow_guest_submissions' => false]),
        'in the bin' => $form->delete(),
        'archived' => app(FormService::class)->archive($form, $owner),
    };

    return Form::withTrashed()->whereKey($form->id)->firstOrFail();
}

it('agrees with the public link on every state a form can be in', function (string $case, bool $reachable): void {
    $form = reachabilityForm($case, $this->tenant, $this->owner);

    expect(GuestReachability::reachable($form))->toBe($reachable);

    $status = $this->withoutVite()->get('http://acme.meridian.test/f/intake')->status();

    expect($status)->toBe($reachable ? 200 : 404, "the public link answered {$status} for a form that is {$case}");
})->with([
    'published, with a link and guests allowed' => ['open', true],
    'with guest access off' => ['guest access off', false],
    'never published' => ['never published', false],
    'in the bin' => ['in the bin', false],
    'archived — the link still answers today' => ['archived', true],
]);

it('is not reachable without a public link, which nothing could send a respondent to', function (): void {
    $form = guestForm($this->tenant, $this->owner, 'intake');
    expect(GuestReachability::reachable($form))->toBeTrue();

    $form->update(['public_slug' => null]);

    expect(GuestReachability::reachable($form->refresh()))->toBeFalse();
});
