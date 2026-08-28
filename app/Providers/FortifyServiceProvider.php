<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Auth\RlsAwareUserProvider;
use App\Http\Requests\Auth\RlsAwareTwoFactorLoginRequest;
use App\Services\Auth\GoogleSignInGate;
use App\Services\Settings\RegistrationGate;
use App\Support\Auth\PasswordPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Make the two-factor challenge reachable (Increment J3c1). Fortify resolves the pending user with
        // a STATIC `$model::find()` on the default connection, which bypasses RlsAwareUserProvider and is
        // hidden by the join-shape `users` RLS policy mid-login — so the page 302'd to /login forever and
        // anyone who enrolled in 2FA was locked out. The full argument is in the subclass's docblock.
        //
        // ⚠️ THIS BINDING IS THE ONLY SEAM FORTIFY OFFERS. None of its contracts covers the form request,
        // and `Fortify::ignoreRoutes()` is a single boolean that would disable its ~25 routes rather than
        // these two. Both of Fortify's call sites are method-injected type-hints on
        // TwoFactorAuthenticatedSessionController, and route dependencies resolve through the container
        // (`ResolvesRouteDependencies` calls `make()`), so binding the parent to the subclass is enough.
        //
        // In register(), not boot(): the framework's FormRequest lifecycle is attached by container
        // `resolving()`/`afterResolving()` callbacks whose type matching is `instanceof`-based, so they
        // still fire for the subclass and validation/redirector wiring is unchanged.
        $this->app->bind(TwoFactorLoginRequest::class, RlsAwareTwoFactorLoginRequest::class);
    }

    public function boot(): void
    {
        // The pre-auth user provider that resolves users on the least-privilege `pgsql_auth` connection
        // so login/registration/password-reset work despite the fail-closed join-shape RLS on `users`.
        Auth::provider('rls_aware', fn ($app, array $config): RlsAwareUserProvider => new RlsAwareUserProvider(
            $app['hash'],
            $config['model'],
        ));

        // Breached-password check (OWASP ASVS L1) + a 12-char minimum + the four character classes
        // (user decision of record 2026-08-09), inherited by registration, password reset, password
        // update AND invitation-accept via Password::defaults() in PasswordValidationRules.
        //
        // ⚠️ THE CHAIN ORDER IS COSMETIC AND MUST NOT BE READ AS PRECEDENCE. `Rules\Password::passes()`
        // runs the length and all four class checks inside ONE inner validator, and reaches
        // `uncompromised()` only if that validator passes — structurally, whatever order they are
        // written in. So every class failure short-circuits the HIBP lookup, which is why a fixture that
        // fails a class makes a breach test pass with ZERO requests sent. `AuthenticationTest` guards
        // both breach cases against exactly that.
        //
        // `letters()` is implied by `mixedCase()` at runtime and is named anyway: four classes is the
        // decision, and a reader of this line should not have to know that one call subsumes another.
        // The minimum lives on PasswordPolicy so the number the strength checklist renders and the
        // number the validator enforces cannot be two numbers.
        Password::defaults(fn () => Password::min(PasswordPolicy::MIN_LENGTH)
            ->letters()
            ->mixedCase()
            ->numbers()
            ->symbols()
            ->uncompromised());

        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        // Inertia views. config/fortify.php sets `views => true` so these routes are registered.
        // (This comment used to say the pages were "unstyled in B1; the design-system-styled versions land
        // with the app shell in Increment C" — that has been false since C1 shipped all eight of them, and
        // it was still being read as current in J3a. A stale note about the state of the code is worse than
        // no note, because it is trusted.)
        // canRegister (I5) comes from the SAME RegistrationGate the GateRegistration middleware consults,
        // so the link on this page and the reachability of the route it points at are one answer rather
        // than two that agree until they don't. Resolved per request (it depends on the host and on two
        // settings rows), never memoized here.
        // canUseGoogle (J3c2) works the same way and for the same reason — one gate, and the button's
        // visibility comes from the object the redirect route itself asks, so a visible control can never
        // point at a 404. ⚠️ It is deliberately NOT `RegistrationGate`: that answers "may a stranger create
        // an account here", while this button is mostly pressed by people who already have one. See
        // GoogleSignInGate for the accepted cost of the other direction (ADR-0019 §D8).
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canRegister' => app(RegistrationGate::class)->allows($request),
            'canUseGoogle' => app(GoogleSignInGate::class)->allows($request),
        ]));
        // `passwordPolicy` (J3b) is the SERVER'S OWN RULE LIST, shipped so `MdsPasswordStrength` renders it
        // rather than restating it. Per-view rather than a shared Inertia prop, on HandleInertiaRequests'
        // own stated criterion — a shared prop is for something that paints every page, and this paints
        // four. `PasswordPolicy::requirements()` is pure, so there is nothing to memoize.
        // ⚠️ THIS CLOSURE GAINED ITS `Request` IN J3c2 AND THAT IS NOT COSMETIC. Fortify passes one to every
        // view closure; this page simply had nothing host-dependent to say until now, while `loginView`
        // above always did. `canUseGoogle` is a question about the HOST, so the parameter is the whole
        // mechanism rather than a signature tidy-up.
        Fortify::registerView(fn (Request $request) => Inertia::render('auth/Register', [
            'passwordPolicy' => PasswordPolicy::requirements(),
            'canUseGoogle' => app(GoogleSignInGate::class)->allows($request),
        ]));
        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('auth/ForgotPassword'));
        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->input('email'),
            'token' => $request->route('token'),
            'passwordPolicy' => PasswordPolicy::requirements(),
        ]));
        // ⚠️ `name`/`email` ARE NOT DECORATION — THEY ARE THE ESCAPE HATCH FROM A LOCKOUT J3a WOULD
        // OTHERWISE CREATE. `UpdateUserProfileInformation` nulls `email_verified_at` whenever the address
        // changes (correctly — a new address is unproven), and J3a mounts `verified` on the authenticated
        // tenant group. So a member who fixes a typo in their own email is bounced HERE on their very next
        // page load, and `/settings` — the only surface with an email field — is inside the gate they just
        // fell behind. Without a correction form on this page the sole remaining action is "resend", which
        // resends to the typo'd address forever.
        //
        // `PUT /user/profile-information` is a Fortify route carrying only `auth`, so it stays reachable
        // while unverified; this page just needs the values to seed the form with. Fortify's own
        // `EmailVerificationPromptController` passes nothing, which is why they are added here.
        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'name' => $request->user()?->name,
            'email' => $request->user()?->email,
        ]));
        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));
        // ⚠️ THE `url.intended` WRITE IS THE OTHER HALF OF I8a'S STEP-UP, AND WITHOUT IT THE FLOW STRANDS
        // PEOPLE. Fortify answers a successful confirmation with `redirect()->intended(...)`, but Laravel
        // only RECORDS an intended URL when RequirePassword itself issues the redirect. Anyone who arrives
        // here from a LINK — which the 2FA enrolment panel deliberately offers, because its two JSON
        // sidecars get a 423 rather than a redirect (see TwoFactorSetup.vue) — would otherwise be dropped
        // on `/dashboard` having just confirmed a password in order to reach a different page entirely.
        //
        // Same host only. `url()->previous()` returns the `Referer` verbatim when one is present, so an
        // attacker-chosen value would make this an open redirect; Laravel's own Redirector::guest() takes
        // that risk, and this path does not need to. The check covers tenant subdomains and the central
        // console alike, because this route is served on whichever host the user is already on.
        Fortify::confirmPasswordView(function (Request $request) {
            $previous = url()->previous();

            if (parse_url($previous, PHP_URL_HOST) === $request->getHost()) {
                $request->session()->put('url.intended', $previous);
            }

            return Inertia::render('auth/ConfirmPassword');
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by((string) $request->session()->get('login.id')));

        // ── M43: the Fortify routes Fortify itself leaves unmetered ────────────────────────────────────
        //
        // Bound by App\Http\Middleware\ThrottleFortifyEndpoints, not by a `throttle:` alias, because
        // Fortify has no per-route middleware hook — that class carries the route-name => limiter map and
        // the reasoning. Every name below must appear in it; FortifyRateLimitTest asserts both directions.
        //
        // ⚠️ THE NUMBERS ARE SIZED AGAINST A PROPERTY OF ThrottleRequests, NOT AGAINST INTUITION. It
        // counts SUCCESSES as well as failures and never clears the bucket — the same behaviour
        // docs/security-threat-model.md §8 records for `login`, where six successful sign-ins in a minute
        // lock the account out for the rest of it. So a door a legitimate session opens repeatedly gets
        // headroom, and a door that exists only to test a secret does not.
        //
        // ⚠️ AND A PER-MINUTE ARM ALONE IS NOT A BOUND ON A MAIL CANNON: 5/min is 300/hour. The two
        // guest-reachable mail paths therefore carry an HOURLY arm as well, keyed on the address rather
        // than the identity, because that is the arm an enumerating script actually runs into. Both arms
        // are checked before either is incremented (ThrottleRequests::handleRequest), so tripping the
        // hourly arm does not also burn the minute.
        //
        // The `by()` prefixes match the sixteen limiters already registered across this provider and
        // AppServiceProvider. They are for readability only: the framework namespaces every bucket as
        // md5($limiterName.$limit->key), so two limiters cannot collide even with identical keys.

        RateLimiter::for('password-reset-request', fn (Request $request): array => [
            Limit::perMinute(5)->by('pwreq:'.Str::transliterate(Str::lower((string) $request->input(Fortify::email())).'|'.$request->ip())),
            Limit::perHour(30)->by('pwreq-ip:'.$request->ip()),
        ]);

        RateLimiter::for('password-reset', fn (Request $request): array => [
            Limit::perMinute(5)->by('pwreset:'.Str::transliterate(Str::lower((string) $request->input(Fortify::email())).'|'.$request->ip())),
            Limit::perHour(30)->by('pwreset-ip:'.$request->ip()),
        ]);

        // No identity exists yet, so the address is the only arm available — and the HOST is part of the
        // key for the reason the saml-* limiters give: RegistrationGate resolves per host, so a tenant's
        // open workspace is a different door from the central one, and one corporate NAT must not be able
        // to exhaust another workspace's budget. The address stays in the key because the host is
        // attacker-chosen.
        RateLimiter::for('registration', fn (Request $request): array => [
            Limit::perMinute(5)->by('reg:'.$request->ip().':'.$request->getHost()),
            Limit::perHour(20)->by('reg-hr:'.$request->ip().':'.$request->getHost()),
        ]);

        // Authenticated from here down. `?? ip` is a floor that should never fire — measured on the live
        // route table, `Authenticate` runs at index 5 and this middleware at 6 — and it is written as a
        // fallback rather than an assertion because a limiter closure is the wrong place to fatal. Without
        // it, `->by('')` would be one deployment-wide bucket, which is the M30 defect exactly.
        RateLimiter::for('password-update', fn (Request $request): Limit => Limit::perMinute(6)
            ->by('pwupd:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // ⚠️ TEN RATHER THAN SIX, AND THE REASON IS MEASURED, NOT CAUTIOUS. This is the redemption door
        // for RequireRecentPassword, whose SAML twin has been bounded at 20/min since P1c — so ten is
        // already tighter than the path it is symmetric with. tests/e2e/support/console.ts posts this form
        // on every console visit whose 900s window has expired, across three viewport projects, and
        // ThrottleRequests counts those successes. Six would put a red E2E run inside the margin for a
        // security difference between six guesses a minute and ten.
        RateLimiter::for('password-confirm', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('pwconf:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // The tightest of the seven, and the one with the smallest secret behind it: a SIX-DIGIT TOTP with
        // no other bound anywhere — the vendor controller counts nothing and no Lockout listener exists.
        // Five deliberately matches `two-factor` above: same secret, same guess space, same answer.
        RateLimiter::for('two-factor-confirm', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('2fac:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Enrolment lifecycle rather than secret-testing, and one shared bucket because no single flow
        // spends one hit of enable AND disable AND regenerate. Enrol-then-confirm IS one such flow, which
        // is why two-factor.confirm is split out above rather than folded in here.
        RateLimiter::for('two-factor-manage', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('2fam:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
