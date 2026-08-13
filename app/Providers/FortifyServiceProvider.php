<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Auth\RlsAwareUserProvider;
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

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
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

        // Inertia views (unstyled in B1; the design-system-styled versions land with the app shell in
        // Increment C). config/fortify.php sets `views => true` so these routes are registered.
        // canRegister (I5) comes from the SAME RegistrationGate the GateRegistration middleware consults,
        // so the link on this page and the reachability of the route it points at are one answer rather
        // than two that agree until they don't. Resolved per request (it depends on the host and on two
        // settings rows), never memoized here.
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canRegister' => app(RegistrationGate::class)->allows($request),
        ]));
        Fortify::registerView(fn () => Inertia::render('auth/Register'));
        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('auth/ForgotPassword'));
        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->input('email'),
            'token' => $request->route('token'),
        ]));
        Fortify::verifyEmailView(fn () => Inertia::render('auth/VerifyEmail'));
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
    }
}
