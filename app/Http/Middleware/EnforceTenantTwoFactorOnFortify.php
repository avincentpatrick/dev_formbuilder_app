<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Settings\RegistrationGate;
use App\Services\Settings\TenantSettingRegistry;
use App\Services\Settings\TwoFactorEnforcementGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies org-level 2FA enforcement to the Fortify ACCOUNT-WRITE routes only (Increment M68).
 *
 * ⚠️ MOUNTED ON `config/fortify.php`'s `middleware` ARRAY, WHICH APPLIES TO **EVERY** FORTIFY ROUTE — the
 * same mount, and the same first-line guard, as {@see GateRegistration} and {@see ThrottleFortifyEndpoints}.
 * Fortify has no per-route middleware hook: one config-level array registers every route it ships, which is
 * why a gate that must reach two of its twenty-six routes has to select them itself.
 *
 * ── WHAT WAS UNGATED ──────────────────────────────────────────────────────────────────────────────────
 * `M66` mounted {@see EnforceTenantTwoFactor} on the `/api/v1` token-mint group, closing the row that said
 * an unenrolled member under enforcement could still leave with a bearer token. The backlog row filed
 * beside that closure is this one: the mint was never the only way past the gate. The Fortify group serves
 * tenant subdomains — `RequirePlatformHost` admits subdomains of the central domain — so an unenrolled
 * member bounced from every page of their workspace could still `PUT /user/profile-information` and
 * `PUT /user/password`.
 *
 * ── ⛔ WHY THIS IS A PER-ROUTE MAP AND NOT A GROUP-LEVEL MOUNT. THREE CARVE-OUTS, AND THE ROW NAMED ONE.
 * Adding `EnforceTenantTwoFactor` to the Fortify array directly would gate all twenty-six routes and
 * break the design it enforces:
 *
 *  1. **The 2FA enrolment routes.** `two-factor.enable` / `.confirm` / `.disable` / `.qr-code` /
 *     `.secret-key` / `.recovery-codes` / `.regenerate-recovery-codes` are the only way to satisfy the
 *     gate. Gating them is the redirect-to-itself that {@see EnforceTenantTwoFactor}'s docblock calls the
 *     whole design. This is the one the row names.
 *  2. **`POST /logout`.** That docblock names it in terms — *"a Fortify route in its own group, so it is
 *     naturally outside this gate — do not 'tidy' it inside"* — because *"enrol or leave"* must have two
 *     doors. A group mount closes the second one.
 *  3. **`password.confirm` / `.confirmation` / `.confirm.store`.** `config/fortify.php` enables
 *     `twoFactorAuthentication(['confirmPassword' => true])`, so enrolment goes THROUGH the step-up
 *     confirmation — measured on the live route table, where `two-factor.enable` carries
 *     `Illuminate\Auth\Middleware\RequirePassword`. Gating the confirmation locks the escape hatch one
 *     step further back than (1), which is why reading (1) alone is not enough.
 *
 * Everything else falls through: the guest routes (`login`, `register`, `password.request` and the reset
 * pair) have no authenticated user, so the gate would no-op on them anyway, and the `verification.*` trio
 * must stay open or an unverified, unenrolled member deadlocks against a gate the app applies first.
 *
 * ── ⚠️ THE MAP IS KEYED ON ROUTE NAME, FOR THE REASON `ThrottleFortifyEndpoints` GIVES ──────────────────
 * Three pairs of Fortify routes share a path across verbs, and every Fortify path is routed through
 * `RoutePath::for()` and is configurable while the name is not. ⛔ **AND THE WRITE ROUTES DO NOT WEAR THE
 * OBVIOUS NAMES**: they are `user-profile-information.update` and `user-password.update`, not
 * `user.profile-information` or `password.update` — the latter is the *password-reset* endpoint, which is
 * a GUEST route. A map keyed on the plausible-looking name would gate a route no logged-in member can
 * reach and leave both real ones open, with every behavioural test still green.
 *
 * ── ⛔ WHY IT CANNOT SIMPLY MOUNT `EnforceTenantTwoFactor`, WHICH IS WHAT THE ROW IMPLIES ──────────────
 * ⛔ **THAT WAS BUILT FIRST AND THE GATE COULD NOT EVER FIRE. MEASURED, NOT REASONED: three behavioural
 * cases failed with the mount in place and the write succeeding.** This group carries no tenancy
 * middleware, so {@see EstablishTenantDatabaseContext} resolves **null** here and
 * {@see TenantSettingRegistry::all()} returns `[]` with no ambient tenant — making
 * `security.require_two_factor` read as its sparse default, `false`, for every workspace in the
 * deployment. A mounted, green, permanently blind gate.
 *
 * {@see RegistrationGate} hit the identical wall on `/register` and is the
 * precedent this follows: resolve the workspace from the HOST and read through `getFor()`. The policy
 * itself lives in {@see TwoFactorEnforcementGate} so that this surface and the
 * tenant group cannot answer differently — one gate, two consumers. This class decides only WHICH ROUTES.
 *
 * ── ORDER: MEASURED, NOT REASONED ─────────────────────────────────────────────────────────────────────
 * ⛔ It is deliberately ABSENT from `bootstrap/app.php`'s `priority()` list, and that absence is what puts
 * it in the right place. `SortedMiddleware` hoists LISTED classes forward past unlisted ones, so on the
 * live route table an unlisted entry in this array lands at the END — after `Authenticate:web` (so
 * `$request->user()` is resolved) and after {@see EstablishTenantDatabaseContext} (so the tenant-scoped
 * settings read has its RLS GUC). Both were printed from `Router::gatherRouteMiddleware()` with this entry
 * present and then removed, because `M43` measured that the intuitive answer here is the wrong one.
 * `ThrottleFortifyEndpoints` is in that list for the opposite reason — it must refuse BEFORE the database
 * round trip — so the two are not inconsistent.
 *
 * ⚠️ THIS DOES NOT CLOSE THE MAIL-CANNON ROW ON THE SAME ROUTE, and the two must not be confused.
 * `PUT /user/profile-information` dispatches verification mail to an arbitrary address on every email
 * change; that is a RATE-LIMIT defect, its remedy is a `RateLimiter::for()` plus an entry in
 * `ThrottleFortifyEndpoints::limiters()`, and it stays open. This gate narrows WHO may reach the route
 * under enforcement, which is a smaller and different claim.
 */
final class EnforceTenantTwoFactorOnFortify
{
    public function __construct(private readonly TwoFactorEnforcementGate $gate) {}

    /**
     * The Fortify route names this gate covers — and the single source of truth for both the gate and the
     * test that holds its complement.
     *
     * ⛔ THE COMPLEMENT IS WHAT IS ASSERTED, NOT THIS LIST. `FortifyTwoFactorCoverageTest` discovers every
     * live Fortify write route by controller namespace and requires each one to be either here or in its
     * own `UNGATED_BY_DECISION` list, so a route arriving in a vendor upgrade fails LOUDLY instead of
     * inheriting silence. That is the shape `FortifyRateLimitTest` established for the rate limiters.
     *
     * @return list<string>
     */
    public static function gatedRouteNames(): array
    {
        return [
            // The two the backlog row names. Both are self-scoped account writes, which is why this is a
            // defence-in-depth gap rather than a leak — and why it is still worth closing: under
            // enforcement the workspace has said no member operates here without a second factor, and a
            // member bounced off every page could still change their own email and password.
            'user-profile-information.update',

            // ⚠️ GATING THIS ONE IS A JUDGEMENT AND THE ARGUMENT AGAINST IT IS REAL. `PUT /user/password`
            // re-challenges (it requires `current_password`), and it is also how a member responds to a
            // credential compromise — so gating it means enrolling before rotating. It is gated anyway
            // because the detour is one step and the enrolment routes are deliberately open: the member is
            // never locked out, only sent through the door the workspace asked them to use.
            'user-password.update',
        ];
    }

    public function handle(Request $request, Closure $next): Response
    {
        $name = $request->route()?->getName();

        // Falls through for anything not in the map, so the twenty-four routes above keep the behaviour
        // they have always had and nothing here is gated twice.
        if (! is_string($name) || ! in_array($name, self::gatedRouteNames(), true)) {
            return $next($request);
        }

        // ⛔ `blocksForHost()`, NEVER `blocksAmbient()`. There is no ambient tenant on this group, and the
        // ambient read fails SILENTLY to `false` rather than raising — see this class's docblock.
        if ($this->gate->blocksForHost($request)) {
            return $this->gate->refuse($request);
        }

        return $next($request);
    }
}
