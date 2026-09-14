<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Admin\SuperAdminProvisioner;
use App\Services\Auth\OperatorAccounts;
use App\Support\Auth\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M95 — `platform:super-admin`, the first platform operator on a fresh server.
|--------------------------------------------------------------------------
| ⚠️ THE COMMAND'S WRITES COMMIT OUTSIDE RefreshDatabase. It writes on `pgsql_privileged`, a separate session
| from the default connection this file's transaction wraps, and its postcondition reads on `pgsql_auth`,
| another one — which is the point: an uncommitted write could not satisfy either. So every address here uses
| the marker domain below, and `superAdminCommandPurge()` removes what the case committed through
| `beforeApplicationDestroyed`, the device `tests/Pest.php` records: RefreshDatabase registers its rollback
| earlier, so the purge runs once the test transaction is gone and there is no lock left to wait on. Audits
| first, then tenants, then users.
|
| ⚠️ `fakeHibp()` IS CALLED INSIDE EACH CASE, NEVER IN A `beforeEach`. `Http::fake()` appends a stub rather
| than replacing one, and the FIRST stub that answers wins — so a catch-all fake registered earlier answers
| the breached-password case with an empty body, the verifier reads that as "not breached", and the case goes
| red against a correct command.
|
| ⚠️ THE HOST IS SPELLED OUT on every request, for the reason `CentralHostLoginTest`'s header gives: the
| console routes are bound to `tenancy.central_domain`, and a relative URL resolves against another host.
|
| Helpers are prefixed `superAdminCommand` because Pest loads every test file into one process, and a second
| top-level function with the same name is a fatal on the full suite only.
*/

const SUPER_ADMIN_COMMAND_PASSWORD = 'Ledger-Keeper-2026!';

beforeEach(function (): void {
    $this->beforeApplicationDestroyed(fn () => superAdminCommandPurge());
});

function superAdminCommandEmail(string $local): string
{
    return $local.'-'.Str::lower(Str::random(10)).'@superadmincommandtest.local';
}

/** Delete everything a case committed, by marker, on the privileged connection. */
function superAdminCommandPurge(): void
{
    $connection = DB::connection('pgsql_privileged');

    $userIds = $connection->table('users')->where('email', 'like', '%@superadmincommandtest.local')->pluck('id')->all();
    $tenantIds = $connection->table('tenants')->where('slug', 'like', 'platform-audit-sacmd-%')->pluck('id')->all();

    if ($userIds !== []) {
        $connection->table('audits')->whereIn('user_id', $userIds)->delete();
        $connection->table('audits')->whereIn('auditable_id', $userIds)->delete();
    }

    if ($tenantIds !== []) {
        $connection->table('audits')->whereIn('tenant_id', $tenantIds)->delete();
        // `tenant_users` cascades from both of its foreign keys.
        $connection->table('tenants')->whereIn('id', $tenantIds)->delete();
    }

    if ($userIds !== []) {
        $connection->table('users')->whereIn('id', $userIds)->delete();
    }
}

/**
 * A COMMITTED account, visible to `pgsql_privileged` and `pgsql_auth` alike.
 *
 * @param  array<string, mixed>  $attributes
 */
function superAdminCommandAccount(array $attributes = []): User
{
    /** @var User $user */
    $user = User::on('pgsql_privileged')->forceCreate(array_merge([
        'name' => 'Existing Account',
        'email' => superAdminCommandEmail('existing'),
        'password' => Hash::make('Existing-Account-Pass-1'),
        'email_verified_at' => now(),
        'is_super_admin' => false,
    ], $attributes));

    return $user;
}

/** The committed row as a DIFFERENT role on a different session sees it — never the model the command held. */
function superAdminCommandRow(string $email): ?object
{
    return DB::connection('pgsql_auth')->table('users')->where('email', $email)->first();
}

/** Audit rows about or by these accounts, on the connection that sees every row. */
function superAdminCommandAuditCount(string ...$userIds): int
{
    return DB::connection('pgsql_privileged')->table('audits')
        ->where(fn ($query) => $query->whereIn('auditable_id', $userIds)->orWhereIn('user_id', $userIds))
        ->count();
}

/**
 * The fixture must fail ONLY the breach rule. The framework checks length and the character classes first and
 * reaches the breach lookup only when they pass, so a fixture failing a class would be refused with no request
 * sent, and the breach case would pass for the wrong reason.
 */
function superAdminCommandExpectEveryRuleButBreach(string $password): void
{
    $withoutBreachCheck = Password::min(PasswordPolicy::MIN_LENGTH)->letters()->mixedCase()->numbers()->symbols();

    expect(validator(['password' => $password], ['password' => $withoutBreachCheck])->fails())
        ->toBeFalse('the fixture must satisfy every rule except uncompromised(), or the breach lookup is never reached');
}

it('creates a new operator flagged, verified and unenrolled, with the typed address stored lower-case', function (): void {
    fakeHibp();
    $typed = 'Ops.Lead-'.Str::random(8).'@SuperAdminCommandtest.Local';
    $stored = Str::lower($typed);

    $this->artisan('platform:super-admin', ['email' => $typed, '--name' => 'Ops Lead'])
        ->expectsQuestion(OperatorAccounts::PASSWORD_PROMPT, SUPER_ADMIN_COMMAND_PASSWORD)
        ->expectsQuestion(OperatorAccounts::CONFIRM_PROMPT, SUPER_ADMIN_COMMAND_PASSWORD)
        ->expectsOutputToContain('breach list')
        ->expectsOutputToContain('/admin/two-factor')
        ->expectsOutputToContain('/admin/settings')
        ->assertExitCode(0);

    $row = superAdminCommandRow($stored);

    expect($row)->not->toBeNull('pgsql_auth must see the committed account under its lower-cased address');

    expect($row->email)->toBe($stored)
        ->and((bool) $row->is_super_admin)->toBeTrue()
        ->and($row->email_verified_at)->not->toBeNull()
        ->and($row->password_set_at)->not->toBeNull()
        ->and($row->two_factor_secret)->toBeNull()
        ->and($row->two_factor_confirmed_at)->toBeNull()
        ->and($row->deleted_at)->toBeNull()
        ->and(Hash::check(SUPER_ADMIN_COMMAND_PASSWORD, (string) $row->password))->toBeTrue()
        ->and(superAdminCommandRow($typed))->toBeNull();
});

it('lets the created operator sign in on the central host and sends them to enrolment rather than locking them out', function (): void {
    fakeHibp();
    $typed = 'Night.Shift-'.Str::random(8).'@SuperAdminCommandtest.Local';

    $this->artisan('platform:super-admin', ['email' => $typed, '--name' => 'Night Shift'])
        ->expectsQuestion(OperatorAccounts::PASSWORD_PROMPT, SUPER_ADMIN_COMMAND_PASSWORD)
        ->expectsQuestion(OperatorAccounts::CONFIRM_PROMPT, SUPER_ADMIN_COMMAND_PASSWORD)
        ->assertExitCode(0);

    // Typed exactly as the operator typed it to the command. Sign-in lower-cases it, so this reaches the account
    // only because the command stored the lower-cased form.
    $this->post('http://meridian.test/login', ['email' => $typed, 'password' => SUPER_ADMIN_COMMAND_PASSWORD])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticated();

    // The boundary between two real requests, inline rather than through `consoleEndOfRequest()`, which is
    // declared in another file and undefined when that file is not loaded.
    app('auth')->forgetGuards();

    // `superadmin` would 404 an unflagged account, and `superadmin.mfa` runs before step-up: an unenrolled
    // operator is sent to enrolment. A placeholder two-factor secret would instead have forced a challenge at
    // sign-in that nobody could answer.
    $this->get('http://meridian.test/admin/tenants')
        ->assertRedirect('http://meridian.test/admin/two-factor');
});

it('grants the flag to an existing membership-less account without touching its password or two-factor', function (): void {
    fakeHibp();
    $account = superAdminCommandAccount([
        'email_verified_at' => null,
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now()->subDay(),
    ]);
    $before = DB::connection('pgsql_privileged')->table('users')->where('id', $account->id)->first();

    // No question is expected: an existing account is never given a password. An unexpected prompt fails the case.
    $this->artisan('platform:super-admin', ['email' => $account->email])
        ->expectsOutputToContain('Granted platform super-admin')
        ->assertExitCode(0);

    $after = superAdminCommandRow($account->email);

    expect($after)->not->toBeNull();

    // The fixture is invisible to the app connection, so a grant written through it — or through Eloquent
    // `save()` — would leave the flag false here while reporting success.
    expect((bool) $after->is_super_admin)->toBeTrue()
        ->and($after->email_verified_at)->not->toBeNull()
        ->and($after->password)->toBe($before->password)
        ->and($after->two_factor_secret)->toBe($before->two_factor_secret)
        ->and($after->two_factor_confirmed_at)->toBe($before->two_factor_confirmed_at)
        ->and($after->password_set_at)->toBe($before->password_set_at);

    Http::assertNothingSent();
});

it('is a no-op for an account that is already a platform super-admin', function (): void {
    fakeHibp();
    $account = superAdminCommandAccount(['is_super_admin' => true]);
    $updatedBefore = DB::connection('pgsql_privileged')->table('users')->where('id', $account->id)->value('updated_at');

    $this->artisan('platform:super-admin', ['email' => $account->email])
        ->expectsOutputToContain('already a platform super-admin')
        ->assertExitCode(0);

    expect(DB::connection('pgsql_privileged')->table('users')->where('id', $account->id)->value('updated_at'))
        ->toBe($updatedBefore)
        ->and(superAdminCommandAuditCount($account->id))->toBe(0);
});

it('refuses an account that holds a workspace membership, and changes nothing', function (): void {
    fakeHibp();
    $account = superAdminCommandAccount();
    $tenant = committedPlatformTenant('platform-audit-sacmd-'.Str::lower(Str::random(8)));

    DB::connection('pgsql_privileged')->table('tenant_users')->insert([
        'id' => Uuid::uuid7()->toString(),
        'tenant_id' => $tenant->id,
        'user_id' => $account->id,
        'status' => 'active',
        'joined_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('platform:super-admin', ['email' => $account->email])
        ->expectsOutputToContain('workspace membership')
        ->assertExitCode(1);

    expect((bool) superAdminCommandRow($account->email)?->is_super_admin)->toBeFalse();
});

it('refuses a breached password that passes every other rule, after actually asking the breach service', function (): void {
    $breached = 'Trustno1-Trustno1!';
    superAdminCommandExpectEveryRuleButBreach($breached);
    fakeHibp([$breached]);
    $email = superAdminCommandEmail('breached');

    $this->artisan('platform:super-admin', ['email' => $email, '--name' => 'Breached'])
        ->expectsQuestion(OperatorAccounts::PASSWORD_PROMPT, $breached)
        ->expectsQuestion(OperatorAccounts::CONFIRM_PROMPT, $breached)
        ->expectsOutputToContain('data leak')
        ->assertExitCode(2);

    expect(superAdminCommandRow($email))->toBeNull();

    Http::assertSent(fn (ClientRequest $request): bool => str_starts_with(
        $request->url(),
        'https://api.pwnedpasswords.com/range/'.substr(strtoupper(sha1($breached)), 0, 5),
    ));
    Http::assertSentCount(1);
});

it('refuses a password that misses a character class, before any breach lookup', function (): void {
    fakeHibp();
    $email = superAdminCommandEmail('weak');

    $this->artisan('platform:super-admin', ['email' => $email, '--name' => 'Weak'])
        ->expectsQuestion(OperatorAccounts::PASSWORD_PROMPT, 'correct-horse-battery-staple')
        ->expectsQuestion(OperatorAccounts::CONFIRM_PROMPT, 'correct-horse-battery-staple')
        ->expectsOutputToContain('uppercase')
        ->assertExitCode(2);

    expect(superAdminCommandRow($email))->toBeNull();

    Http::assertSentCount(0);
});

it('refuses a confirmation that does not match, before any breach lookup', function (): void {
    fakeHibp();
    $email = superAdminCommandEmail('mismatch');

    $this->artisan('platform:super-admin', ['email' => $email, '--name' => 'Mismatch'])
        ->expectsQuestion(OperatorAccounts::PASSWORD_PROMPT, SUPER_ADMIN_COMMAND_PASSWORD)
        ->expectsQuestion(OperatorAccounts::CONFIRM_PROMPT, SUPER_ADMIN_COMMAND_PASSWORD.'x')
        ->expectsOutputToContain('do not match')
        ->assertExitCode(2);

    expect(superAdminCommandRow($email))->toBeNull();

    Http::assertNothingSent();
});

it('refuses to create an account from a non-interactive run', function (): void {
    fakeHibp();
    $email = superAdminCommandEmail('scripted');

    $this->artisan('platform:super-admin', ['email' => $email, '--name' => 'Scripted', '--no-interaction' => true])
        ->expectsOutputToContain('interactive console session')
        ->assertExitCode(2);

    expect(superAdminCommandRow($email))->toBeNull();
});

it('refuses a soft-deleted account', function (): void {
    fakeHibp();
    $account = superAdminCommandAccount(['deleted_at' => now()]);

    $this->artisan('platform:super-admin', ['email' => $account->email])
        ->expectsOutputToContain('is deleted')
        ->assertExitCode(1);

    expect((bool) superAdminCommandRow($account->email)?->is_super_admin)->toBeFalse();
});

it('refuses when pgsql_privileged connects as a role that does not bypass row-level security', function (): void {
    fakeHibp();
    $email = superAdminCommandEmail('nobypass');
    $original = config('database.connections.pgsql_privileged');

    try {
        // The application role: the table owner, under FORCE, with no GUC — the exact configuration in which
        // the seeders' zero-row guard was measured blind.
        config()->set('database.connections.pgsql_privileged.username', config('database.connections.pgsql.username'));
        config()->set('database.connections.pgsql_privileged.password', config('database.connections.pgsql.password'));
        DB::purge('pgsql_privileged');

        $this->artisan('platform:super-admin', ['email' => $email, '--name' => 'No Bypass'])
            ->expectsOutputToContain('BYPASSRLS')
            ->assertExitCode(1);
    } finally {
        // Restored BEFORE the purge callback runs, or the purge would run as that role and delete nothing.
        config()->set('database.connections.pgsql_privileged', $original);
        DB::purge('pgsql_privileged');
    }

    expect(DB::connection('pgsql_privileged')->table('users')->where('email', $email)->exists())->toBeFalse();
});

it('rejects a display name longer than the column, rather than failing on the insert', function (): void {
    fakeHibp();
    $email = superAdminCommandEmail('longname');

    $this->artisan('platform:super-admin', ['email' => $email, '--name' => str_repeat('a', OperatorAccounts::NAME_MAX + 1)])
        ->expectsOutputToContain((string) OperatorAccounts::NAME_MAX)
        ->assertExitCode(2);

    expect(superAdminCommandRow($email))->toBeNull();
});

it('rejects an address that is not an email', function (): void {
    fakeHibp();

    $this->artisan('platform:super-admin', ['email' => 'not-an-email', '--name' => 'Nobody'])
        ->expectsOutputToContain('email')
        ->assertExitCode(2);
});

it('accepts exactly one affected row and refuses any other count', function (): void {
    expect(SuperAdminProvisioner::assertExactlyOne(1))->toBe(1)
        ->and(fn () => SuperAdminProvisioner::assertExactlyOne(0))->toThrow(RuntimeException::class, 'exactly one')
        ->and(fn () => SuperAdminProvisioner::assertExactlyOne(2))->toThrow(RuntimeException::class, 'exactly one');
});

it('writes no audit row for a created or a granted operator, the deliberate gap its docblock records', function (): void {
    fakeHibp();
    $existing = superAdminCommandAccount();
    $newEmail = superAdminCommandEmail('unaudited');
    $auditsBefore = DB::connection('pgsql_privileged')->table('audits')->count();

    $this->artisan('platform:super-admin', ['email' => $newEmail, '--name' => 'Unaudited'])
        ->expectsQuestion(OperatorAccounts::PASSWORD_PROMPT, SUPER_ADMIN_COMMAND_PASSWORD)
        ->expectsQuestion(OperatorAccounts::CONFIRM_PROMPT, SUPER_ADMIN_COMMAND_PASSWORD)
        ->assertExitCode(0);

    $this->artisan('platform:super-admin', ['email' => $existing->email])->assertExitCode(0);

    $created = superAdminCommandRow($newEmail);

    expect($created)->not->toBeNull();

    expect(superAdminCommandAuditCount((string) $created->id, $existing->id))->toBe(0)
        ->and(DB::connection('pgsql_privileged')->table('audits')->count())->toBe($auditsBefore);
});
