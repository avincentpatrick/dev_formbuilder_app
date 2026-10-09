<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\TenantInvitationNotification;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M154 (`R-66bc91ca`, from `D50`) — wherever a tester is invited, someone is told to look in spam.
|--------------------------------------------------------------------------
| The first invitation to the testing server landed in a spam folder, and nothing in the product said that could
| happen. Two places now say it: the email itself (so the invitee marks it "Not spam" and the next ones arrive),
| and the inviter's confirmation (so the person who sent it knows what to tell someone who says it never came).
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('asks the invitee to mark the invitation "Not spam" if it landed there', function (): void {
    $mail = (new TenantInvitationNotification('Acme', 'https://acme.meridian.test/invitations/token'))
        ->toMail(new AnonymousNotifiable);

    expect(in_array(
        'If this email landed in your spam or junk folder, mark it "Not spam" so the next ones reach your inbox.',
        [...$mail->introLines, ...$mail->outroLines],
        true,
    ))->toBeTrue()
        // The non-vacuity partner: the lines this reads are the email's real ones.
        ->and(in_array('This invitation will expire in 7 days.', $mail->outroLines, true))->toBeTrue();
});

it('tells the inviter where a missing invitation usually is', function (): void {
    Notification::fake();

    $tenant = inboxTenant('acme');
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    $this->actingAs($admin)
        ->post('http://acme.meridian.test/members/invitations', ['email' => 'tester@example.test', 'role' => 'form_editor'])
        ->assertRedirect()
        ->assertSessionHas('toast', [
            'type' => 'success',
            'message' => 'Invitation sent to tester@example.test. If it does not arrive, ask them to check their spam or junk folder.',
        ]);
});
