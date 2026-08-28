<?php

use App\Http\Middleware\AppSecurityHeaders;
use App\Http\Middleware\EstablishTenantDatabaseContext;
use App\Http\Middleware\GateRegistration;
use App\Http\Middleware\RequirePlatformHost;
use App\Http\Middleware\ThrottleFortifyEndpoints;
use Laravel\Fortify\Features;

return [

    /*
    |--------------------------------------------------------------------------
    | Fortify Guard
    |--------------------------------------------------------------------------
    |
    | Here you may specify which authentication guard Fortify will use while
    | authenticating users. This value should correspond with one of your
    | guards that is already present in your "auth" configuration file.
    |
    */

    'guard' => 'web',

    /*
    |--------------------------------------------------------------------------
    | Fortify Password Broker
    |--------------------------------------------------------------------------
    |
    | Here you may specify which password broker Fortify can use when a user
    | is resetting their password. This configured value should match one
    | of your password brokers setup in your "auth" configuration file.
    |
    */

    'passwords' => 'users',

    /*
    |--------------------------------------------------------------------------
    | Username / Email
    |--------------------------------------------------------------------------
    |
    | This value defines which model attribute should be considered as your
    | application's "username" field. Typically, this might be the email
    | address of the users but you are free to change this value here.
    |
    | Out of the box, Fortify expects forgot password and reset password
    | requests to have a field named 'email'. If the application uses
    | another name for the field you may define it below as needed.
    |
    */

    'username' => 'email',

    'email' => 'email',

    /*
    |--------------------------------------------------------------------------
    | Lowercase Usernames
    |--------------------------------------------------------------------------
    |
    | This value defines whether usernames should be lowercased before saving
    | them in the database, as some database system string fields are case
    | sensitive. You may disable this for your application if necessary.
    |
    */

    'lowercase_usernames' => true,

    /*
    |--------------------------------------------------------------------------
    | Home Path
    |--------------------------------------------------------------------------
    |
    | Here you may configure the path where users will get redirected during
    | authentication or password reset when the operations are successful
    | and the user is authenticated. You are free to change this value.
    |
    */

    'home' => '/dashboard',

    /*
    |--------------------------------------------------------------------------
    | Fortify Routes Prefix / Subdomain
    |--------------------------------------------------------------------------
    |
    | Here you may specify which prefix Fortify will assign to all the routes
    | that it registers with the application. If necessary, you may change
    | subdomain under which all of the Fortify routes will be available.
    |
    */

    'prefix' => '',

    'domain' => null,

    /*
    |--------------------------------------------------------------------------
    | Fortify Routes Middleware
    |--------------------------------------------------------------------------
    |
    | Here you may specify which middleware Fortify will assign to the routes
    | that it registers with the application. If necessary, you may change
    | these middleware but typically this provided default is preferred.
    |
    */

    // RequirePlatformHost (H22a / ADR-0012) is appended deliberately. Fortify registers /login,
    // /register and /forgot-password with 'domain' => null, and `auth` is priority-ordered AHEAD of the
    // tenancy pipeline, so an unauthenticated request to any tenant route redirects to /login on
    // WHATEVER host it arrived at — including a tenant's custom domain, where the platform's credential
    // form would then render. It allows the central host and its subdomains (tenant users legitimately
    // log in at acme.meridian.test/login) and 404s exactly one class of host: a custom domain.
    // AppSecurityHeaders (I1) is appended for the surface it matters on most: a framed login form is the
    // textbook clickjacking target, and these routes are the only ones in the app that render a credential
    // prompt. See that class for why the guest runtime deliberately makes the opposite choice.
    // GateRegistration (I5 / PRD Feature #10) closes /register when the platform's open-signup toggle is
    // off, or when the tenant whose subdomain the request arrived on is invitation-only. It is appended
    // here because Fortify has no per-route middleware hook — Features::registration() registers both verbs
    // with THIS list — which is exactly why that class checks `$request->is('register')` before it does
    // anything: applied indiscriminately it would 404 /login for everyone.
    //
    // ⚠️ EstablishTenantDatabaseContext (J3b) IS NOT AN ENHANCEMENT — WITHOUT IT SIX ENDPOINTS ON THIS
    // GROUP WROTE ZERO ROWS, SILENTLY, AND HAD DONE SINCE PHASE 0. `users_app_update` is permissive, but
    // PostgreSQL applies SELECT policies to an UPDATE whose WHERE reads a column, and
    // `users_users_visibility` needs `app.current_user_id` or an ACTIVE co-tenant. With neither GUC set
    // the row is invisible to its own update: no rows affected, no exception, and Eloquent's save() does
    // not inspect the count, so every caller was told it worked. The casualties were
    // PUT /user/profile-information, PUT /user/password, the four 2FA writes — and
    // GET /email/verify/{id}/{hash}, which made J3a's `verified` gate a permanent lockout for anyone who
    // had to pass it. `tests/Feature/Auth/FortifyRouteContextTest.php` pins all five.
    //
    // It is appended HERE for the same reason GateRegistration is: Fortify has no per-route middleware
    // hook. And bootstrap/app.php's priority() cannot substitute for this line — priority REORDERS
    // middleware a route already carries and never adds one (see the note at that call site). What
    // priority does contribute is the ordering: this class sits after AuthenticatesRequests there, so
    // `$request->user()` is resolved before it is read.
    //
    // Safe on the guest routes in this same group: resolveTenant() returns null when no tenant is bound
    // and `$request->user()` is null for a guest, so /login, /register and /forgot-password apply
    // (null, null) — the value they already had. Note the tenant is null on ALL of these, subdomain or
    // not, because stancl's identification middleware is not on this group either; `users` is a global
    // table and the policy's self arm (`id = app.current_user_id`) is the one that matters here.
    //
    // ⚠️ THE ONE THING THIS DOES NOT COVER, stated rather than discovered later: it runs BEFORE the
    // controller, so it fixes writes by an already-authenticated user. A write issued AFTER Auth::login()
    // in the same request still has no user GUC — the trap SsoUserProvisioner.php:145 and
    // E2eSeeder both document. Nothing on `users` does that today (`last_login_at` belongs to
    // `sso_connections`), so this is a boundary to respect, not a gap to close.
    //
    // ⚠️ ThrottleFortifyEndpoints (M43) IS HERE FOR THE THIRD TIME FOR THE SAME REASON — no per-route hook.
    // Fortify ships `throttle:` on four routes only (`POST /login`, `POST /two-factor-challenge` and the
    // two verification routes, the last pair at the vendor's literal `6,1` because `limiters` below names
    // no `verification` key). Everything else accepting a credential was unmetered, including three routes
    // reachable with NO SESSION AT ALL: `POST /forgot-password`, `POST /reset-password` and `POST /register`.
    // It matches on route NAME — three Fortify GET/POST pairs share a path — and falls through for anything
    // not in its map, so nothing here is throttled twice.
    //
    // ⚠️ Its position in THIS array is not what decides when it runs — bootstrap/app.php's priority()
    // does, and the reason it is listed there is measured rather than argued: see that call site. In
    // short, an unlisted middleware still ends up after `auth`, so the entry is about refusing BEFORE
    // EstablishTenantDatabaseContext's database round trip, not about resolving the user.
    'middleware' => [
        'web',
        RequirePlatformHost::class,
        AppSecurityHeaders::class,
        GateRegistration::class,
        ThrottleFortifyEndpoints::class,
        EstablishTenantDatabaseContext::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | By default, Fortify will throttle logins to five requests per minute for
    | every email and IP address combination. However, if you would like to
    | specify a custom rate limiter to call then you may specify it here.
    |
    */

    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
    ],

    /*
    |--------------------------------------------------------------------------
    | Register View Routes
    |--------------------------------------------------------------------------
    |
    | Here you may specify if the routes returning views should be disabled as
    | you may not need them when building your own application. This may be
    | especially true if you're writing a custom single-page application.
    |
    */

    'views' => true,

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | Some of the Fortify features are optional. You may disable the features
    | by removing them from this array. You're free to only remove some of
    | these features or you can even remove all of these if you need to.
    |
    */

    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
        Features::updateProfileInformation(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
            // 'window' => 0,
        ]),
    ],

];
