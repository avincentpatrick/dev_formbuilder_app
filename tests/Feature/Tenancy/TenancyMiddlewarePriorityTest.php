<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EstablishTenantDatabaseContext;
use App\Http\Middleware\InitializeTenancyByPublicHost;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
| ADR-0002 §D3 fixes where tenant identification sits in the pipeline: AFTER Authenticate (so
| EstablishTenantDatabaseContext can read the user for app.current_user_id) and BEFORE
| SubstituteBindings (or a route-bound tenant-scoped model resolves with no tenant context and 404s
| under RLS). bootstrap/app.php states that ordering; this file proves it, because a comment cannot
| survive a `composer update` and the failure mode here is SILENT.
|
| The silence is the point. TenancyServiceProvider::makeTenancyMiddlewareHighestPriority() prepends
| six stancl classes to the front of the priority array, and Kernel::prependToMiddlewarePriority() is
| membership-guarded — so a class named in bootstrap/app.php is skipped, and a class that is NOT is
| unshifted to index 0, ahead of EncryptCookies, StartSession and Authenticate. Every test that would
| notice already calls actingAs(), so the whole suite passes either way; the first real symptom would
| be the two route groups that carry no `auth` at all (the guest runtime and invitations) identifying
| a tenant before the session exists. H22a added a second identification class, which is exactly the
| change that would have triggered it.
*/

it('keeps both tenant-identification middleware in the ADR-0002 slot', function (): void {
    $priority = app(HttpKernel::class)->getMiddlewarePriority();

    $authenticate = array_search(AuthenticatesRequests::class, $priority, true);
    $session = array_search(StartSession::class, $priority, true);
    $rlsContext = array_search(EstablishTenantDatabaseContext::class, $priority, true);
    $tokenAuth = array_search(AuthenticateApiToken::class, $priority, true);
    $bindings = array_search(SubstituteBindings::class, $priority, true);

    foreach ([InitializeTenancyByPublicHost::class, InitializeTenancyBySubdomain::class] as $identifier) {
        $at = array_search($identifier, $priority, true);

        // false here means the class is absent from bootstrap/app.php's priority() array, which is
        // precisely the state in which the provider unshifts it to index 0.
        expect($at)->toBeInt()
            // After Authenticate: EstablishTenantDatabaseContext needs the resolved user.
            ->toBeGreaterThan($authenticate)
            // After StartSession: a tenant must never be identified before the session exists.
            ->toBeGreaterThan($session)
            // Before the RLS context, the Sanctum token lookup and route-model binding.
            ->toBeLessThan($rlsContext)
            ->toBeLessThan($tokenAuth)
            ->toBeLessThan($bindings);
    }
});

it('keeps PreventAccessFromCentralDomains between identification and the RLS context', function (): void {
    // Its contract is "the central host must not serve tenant routes", which is only meaningful once a
    // host has been classified. It is defence in depth, not the primary control — on a central host the
    // identification middleware throws NotASubdomainException before this one is ever reached.
    $priority = app(HttpKernel::class)->getMiddlewarePriority();

    expect(array_search(PreventAccessFromCentralDomains::class, $priority, true))
        ->toBeGreaterThan(array_search(InitializeTenancyBySubdomain::class, $priority, true))
        ->toBeGreaterThan(array_search(InitializeTenancyByPublicHost::class, $priority, true))
        ->toBeLessThan(array_search(EstablishTenantDatabaseContext::class, $priority, true));
});

it('leaves nothing the app actually routes on promoted ahead of the session', function (): void {
    // The provider unshifts PreventAccessFromCentralDomains, ByDomain, BySubdomain, ByDomainOrSubdomain,
    // ByPath and ByRequestData. The three classes routes actually carry are named in bootstrap/app.php,
    // so the membership guard skips them; the rest land at the front and stay harmless because priority
    // only reorders middleware a route already has, and no route has them.
    $priority = app(HttpKernel::class)->getMiddlewarePriority();
    $session = array_search(StartSession::class, $priority, true);

    foreach ([InitializeTenancyByPublicHost::class, InitializeTenancyBySubdomain::class, PreventAccessFromCentralDomains::class] as $routed) {
        expect(array_search($routed, $priority, true))->toBeGreaterThan($session);
    }
});
