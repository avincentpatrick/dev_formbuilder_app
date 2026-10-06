<?php

declare(strict_types=1);

use App\Models\Form;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Forms\RedirectTarget;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M130 (`R-db169c29`, `D76`) — the submit response says where a respondent goes after the thank-you screen.
|--------------------------------------------------------------------------
| It rides on the RESPONSE, not the cached schema: an author changes it without republishing, and a response
| queued offline never receives one, so it can never move. Resolved at acceptance — a destination form that has
| stopped taking guests since is `null`, never a dead end.
|
| ⚠️ Helpers are prefixed `submitRedirect*`: Pest loads every test file into one process.
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
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** Another guest-enabled form, at `/f/follow-up`. */
function submitRedirectTarget(mixed $tenant, User $owner): Form
{
    $target = guestForm($tenant, $owner, 'follow-up');
    $target->update(['title' => 'Follow-up visit']);

    return $target->refresh();
}

function submitRedirectPost(object $test, Form $form, string $uuid = '0192f1a2-b3c4-7d5e-8f90-0000000000a1'): mixed
{
    return $test->postJson('http://acme.meridian.test/api/v1/public/f/'.shareTokenFor($form).'/submissions', [
        'answers' => ['full_name' => 'Ada Lovelace'],
        'client_submission_uuid' => $uuid,
    ]);
}

it('sends the respondent to a destination form by its public link, named by its title', function (): void {
    $target = submitRedirectTarget($this->tenant, $this->owner);
    app(FormService::class)->setConfirmationMessage($this->form, null, null, RedirectTarget::form($target->id), $this->owner);

    $response = submitRedirectPost($this, $this->form)->assertCreated();

    expect($response->json('data.redirect.label'))->toBe('Follow-up visit')
        ->and($response->json('data.redirect.url'))->toEndWith('/f/follow-up');
});

it('sends a typed web address as given, named by its host', function (): void {
    app(FormService::class)->setConfirmationMessage($this->form, null, null, RedirectTarget::url('https://health.example.org/next?from=intake'), $this->owner);

    submitRedirectPost($this, $this->form)
        ->assertCreated()
        ->assertJsonPath('data.redirect', ['url' => 'https://health.example.org/next?from=intake', 'label' => 'health.example.org', 'delay_seconds' => 20]);
});

it('tells the respondent\'s page how long to wait, as the builder chose it (M138, D91)', function (): void {
    app(FormService::class)->setConfirmationMessage($this->form, null, null, RedirectTarget::url('https://health.example.org/next', 10), $this->owner);

    submitRedirectPost($this, $this->form)
        ->assertCreated()
        ->assertJsonPath('data.redirect.delay_seconds', 10);
});

it('answers the same destination when the device replays a response the server already has', function (): void {
    app(FormService::class)->setConfirmationMessage($this->form, null, null, RedirectTarget::url('https://health.example.org/next'), $this->owner);

    submitRedirectPost($this, $this->form)->assertCreated();
    enterTenant($this->tenant->id, $this->owner->id);

    submitRedirectPost($this, $this->form)
        ->assertOk()
        ->assertJsonPath('data.redirect.url', 'https://health.example.org/next');
});

it('keeps the respondent on the thank-you screen when there is nowhere to go', function (string $case): void {
    // The positive control first: the same form with a live destination sends the respondent on.
    $target = submitRedirectTarget($this->tenant, $this->owner);
    app(FormService::class)->setConfirmationMessage($this->form, null, null, RedirectTarget::form($target->id), $this->owner);
    submitRedirectPost($this, $this->form, '0192f1a2-b3c4-7d5e-8f90-0000000000b1')
        ->assertCreated()
        ->assertJsonPath('data.redirect.label', 'Follow-up visit');
    enterTenant($this->tenant->id, $this->owner->id);

    match ($case) {
        'no destination is set' => app(FormService::class)->setConfirmationMessage($this->form->refresh(), null, null, RedirectTarget::none(), $this->owner),
        'the destination stopped taking guests' => $target->update(['allow_guest_submissions' => false]),
        'the destination is in the bin' => $target->delete(),
    };

    submitRedirectPost($this, $this->form, '0192f1a2-b3c4-7d5e-8f90-0000000000b2')
        ->assertCreated()
        ->assertJsonPath('data.redirect', null);
})->with([
    'no destination is set',
    'the destination stopped taking guests',
    'the destination is in the bin',
]);
