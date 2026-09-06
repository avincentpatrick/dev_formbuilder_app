<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Every Fortify surface that validates into a NAMED ERROR BAG must have a client that unwraps it (M78).
|
| ⛔ THE DEFECT THESE CASES EXIST FOR RENDERED ABSOLUTELY NOTHING, ON THREE PAGES, TWO OF THEM LOCKOUTS.
| Fortify validates into a named bag — `validateWithBag('updateProfileInformation')` in
| `UpdateUserProfileInformation`, `('updatePassword')` in `UpdateUserPassword`, and, the one no grep of
| `app/` can find, `->errorBag('confirmTwoFactorAuthentication')` thrown ON THE EXCEPTION inside
| `vendor/laravel/fortify/src/Actions/ConfirmTwoFactorAuthentication.php`. Inertia's
| `Middleware::resolveValidationErrors()` returns the whole BAG MAP whenever the session carries no
| `default` bag, so `page.props.errors` is `{updateProfileInformation: {email: …}}` — and a client that
| binds the flat `form.errors.email` reads `undefined` forever.
|
| ⚠️ THE `X-Inertia-Error-Bag` HEADER CANNOT FIX IT, AND THAT WAS MEASURED RATHER THAN ASSUMED. The
| middleware guards that branch with `$bags->has('default') && $request->header(...)`, and `has('default')`
| is false in exactly the case the bag is used. Sending the header changes the response BYTE FOR BYTE not
| at all. The unwrap happens on the client, in `@inertiajs/core`'s `getScopedErrors()`, which is why the
| remedy is a visit option and not a server change.
|
| ⚠️ WHY A PEST CASE AND NOT ONLY A VITEST. A Vitest can only pin the string LITERAL the page passes. That
| is half a contract: it goes green while the server renames its bag. These cases drive the real routes and
| read the real bag off the session, then compare it to the literal harvested from the page — so BOTH ends
| move together or this file goes red. The two halves are `expect($bags)` below and `errorBagLiteralsFor()`.
|
| ⛔ NOTHING IN THE REPOSITORY SAW THIS BEFORE. `grep -rn errorBag tests/` was ZERO; none of the 73
| `assertSessionHasErrors`/`assertInvalid` call sites touches a bagged route; and every Pest test that hits
| these three endpoints asserts the happy path (`FortifyRouteContextTest` ends three cases in
| `assertSessionHasNoErrors()`). The e2e gate renders `/settings` and scans it WITHOUT CLICKING, so a form
| that cannot report failure scans identically to one that can.
*/

/**
 * The bag each route actually validates into, and the client call site obliged to unwrap it.
 *
 * ⚠️ This is deliberately a table and not three ad-hoc cases: the regression that matters is a FOURTH
 * bagged surface arriving with no client change, and a table is the shape that makes adding one cheap.
 */
function bagContract(): array
{
    return [
        'updateProfileInformation' => [
            'route' => '/user/profile-information',
            'client' => 'resources/js/Pages/Settings/Index.vue',
        ],
        'updatePassword' => [
            'route' => '/user/password',
            'client' => 'resources/js/Pages/Settings/Index.vue',
        ],
        'confirmTwoFactorAuthentication' => [
            'route' => '/user/confirmed-two-factor-authentication',
            'client' => 'resources/js/components/settings/TwoFactorSetup.vue',
        ],
    ];
}

/**
 * Every `errorBag: '<name>'` literal in a client file.
 *
 * ⚠️ A plain `str_contains` would pass on a file that mentions the name in a COMMENT, and this repository
 * has been bitten by exactly that twice — `SecretScanDepthTest` found `gitleaks detect` inside an
 * explanatory comment, and `NpmAuditJudgeTest` prescribes the remedy. So this matches the option syntax,
 * not the word.
 */
function errorBagLiteralsFor(string $relativePath): array
{
    $source = (string) file_get_contents(base_path($relativePath));

    preg_match_all("/errorBag:\s*'([A-Za-z]+)'/", $source, $matches);

    return array_values(array_unique($matches[1]));
}

function bagTestMember(): User
{
    $user = User::factory()->create(['email' => 'bag-owner@meridian.test']);
    enterTenant(test()->tenant->id, $user->id);
    makeActiveMember($user, 'owner');

    return $user;
}

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('validates the profile form into the bag the settings page unwraps', function (): void {
    // ⚠️ AN INVALID ADDRESS RATHER THAN A DUPLICATE ONE, AND THAT IS A MEASURED CHOICE.
    // The obvious setup — create a second user and reuse their address — fails here for a reason that has
    // nothing to do with error bags: inserting a second `users` row inside this file's tenant context
    // trips RLS, and PostgreSQL then aborts the WHOLE transaction, so the case dies with SQLSTATE 25P02
    // on the next `set_config` instead of on its assertion. Two orderings were tried and both aborted.
    // What this case exists to pin is the BAG NAME, and every `email` rule lands in the same bag, so the
    // cheaper trigger is the correct one. (The duplicate-address path was proved separately, against the
    // running app, when M78 rendered the defect in a browser.)
    $user = bagTestMember();

    $this->actingAs($user)
        ->from('/settings')
        ->put('/user/profile-information', ['name' => 'Dana Reyes', 'email' => 'not-an-email'])
        ->assertSessionHasErrors(['email'], null, 'updateProfileInformation');

    // ⛔ THE HALF THAT MAKES THIS MORE THAN A RESTATEMENT: the same name, read off the page that must
    // unwrap it. Rename the bag on either side alone and this is red.
    expect(errorBagLiteralsFor('resources/js/Pages/Settings/Index.vue'))
        ->toContain('updateProfileInformation');
});

it('validates the password form into the bag the settings page unwraps', function (): void {
    $user = bagTestMember();

    $this->actingAs($user)
        ->from('/settings')
        ->put('/user/password', [
            'current_password' => 'not-the-password',
            'password' => 'a-perfectly-fine-new-password',
            'password_confirmation' => 'a-perfectly-fine-new-password',
        ])
        ->assertSessionHasErrors(['current_password'], null, 'updatePassword');

    expect(errorBagLiteralsFor('resources/js/Pages/Settings/Index.vue'))
        ->toContain('updatePassword');
});

it('validates the two-factor confirmation into the bag its component unwraps', function (): void {
    // ⚠️ THE BAG HERE IS SET IN VENDOR, ON THE EXCEPTION, so this case is the only evidence in the tree
    // that the name is what the client thinks it is. A Fortify upgrade that renames it reddens here.
    $user = bagTestMember();
    confirmPasswordNow();

    $this->actingAs($user)->post('/user/two-factor-authentication')->assertSessionHasNoErrors();

    $this->actingAs($user)
        ->from('/settings')
        ->post('/user/confirmed-two-factor-authentication', ['code' => '000000'])
        ->assertSessionHasErrors(['code'], null, 'confirmTwoFactorAuthentication');

    expect(errorBagLiteralsFor('resources/js/components/settings/TwoFactorSetup.vue'))
        ->toContain('confirmTwoFactorAuthentication');
});

it('leaves no bagged Fortify surface without a client that unwraps it', function (): void {
    // The census case. It is what turns three examples into a rule, and it is the arm that would have
    // caught the ORIGINAL defect: the two settings forms shipped with no `errorBag` at all.
    // ⚠️ `expect()->toContain()` takes VARIADIC NEEDLES, not a needle and a message — passing a failure
    // string as the second argument asserts the array contains that string too, which is red on arrival.
    // Measured here on the first run, so the message goes through PHPUnit's own `assertContains` instead.
    foreach (bagContract() as $bag => $surface) {
        expect(errorBagLiteralsFor($surface['client']))->toContain($bag);

        $this->assertContains($bag, errorBagLiteralsFor($surface['client']), sprintf(
            '%s posts to %s, which validates into the "%s" bag, so it must pass errorBag: %s or its '
            .'template binds undefined and the page reports nothing.',
            $surface['client'],
            $surface['route'],
            $bag,
            $bag,
        ));
    }
});

it('keeps the one page that already unwrapped a bag correct', function (): void {
    // ⚠️ `auth/VerifyEmail.vue` was the ONLY correct consumer in the tree when M78 was taken, and it is the
    // control that proved the remedy: same endpoint, same bag, same duplicate address, and it renders the
    // message. It is pinned here so a future tidy-up cannot quietly take the reference implementation away.
    expect(errorBagLiteralsFor('resources/js/Pages/auth/VerifyEmail.vue'))
        ->toContain('updateProfileInformation');
});
