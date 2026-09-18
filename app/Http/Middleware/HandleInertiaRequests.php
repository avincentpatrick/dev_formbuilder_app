<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Branding\TenantBrandingService;
use App\Services\Entitlements\EntitlementService;
use App\Support\Audit\ImpersonationContext;
use App\Support\Authorization\ShellAbilities;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        // ⛔ DELIVERED HERE BECAUSE THE CONFIRMATION PAGE IS NOT WHERE IT MATTERS. `RequireRecentPassword`
        //    puts `step_up.discarded` when it bounces a write; `auth/ConfirmPassword` shows it as an
        //    inline notice, and THEN the member confirms and is redirected to `url.intended` — a GET of
        //    the page they came from, re-rendered from the database with their change absent. That page
        //    is the one that has to say so, and it is two requests further on than any flash survives.
        $discardedToast = $this->consumeDiscardedStepUpWrite($request);

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ] : null,
                /*
                 * I11b — is this session being driven by platform staff? Null on every ordinary request,
                 * which is all but a handful in the deployment's lifetime.
                 *
                 * ⚠️ A BOOLEAN AND A URL, NEVER THE OPERATOR'S IDENTITY. The whole of I11a's S2 finding was
                 * that the operator's real name must not reach a tenant surface — `actingAsLabel()` returns
                 * the fixed string "Platform operator" unconditionally for that reason, and this prop
                 * renders on EVERY page in the application, which would make it the widest possible place
                 * to undo it. The banner needs to say THAT it is happening and offer the way out; it does
                 * not need to say who.
                 *
                 * Shared rather than passed per-page because the banner belongs to the app shell: a page
                 * that forgot to send it would silently render an impersonated session as a normal one,
                 * which is the one failure mode this surface cannot have.
                 */
                'impersonating' => ImpersonationContext::operatorId() === null ? null : [
                    'exit_url' => route('impersonate.exit'),
                ],
                // Ability gates the shell/pages need (e.g. the Members nav item + its row actions).
                // The map itself lives in ShellAbilities so that global search's destination catalog
                // (J1c) consumes the SAME definition rather than a second copy of it — the prop shape here
                // is byte-identical to what this block produced inline.
                'can' => ShellAbilities::for($user),
            ],
            // Drives the app shell's theme toggle (C2), the Settings → Appearance panel (G11) and the
            // <html> attribute emission in app.blade.php. Guests resolve to the product defaults, every
            // one of which emits NO attribute (so prefers-color-scheme decides the theme and the type
            // scale / body face stay at their base values).
            'ui' => [
                'theme' => $user?->uiTheme() ?? User::defaultUiTheme(),
                // The tenant's brand ramp (H23a3, ADR-0014), or null. A SHARED prop rather than a
                // per-page one because it paints every page — unlike `customDomains`, which H22b kept
                // off the shared payload precisely because it served one card.
                //
                // Read through TenantBrandingService::isActive(), NEVER Tenant::hasBrandRamp(): stored
                // and active are different questions, and a surface that checks the wrong one ships a
                // Starter+ feature to Free tenants with nothing in the build to notice.
                'brand' => app(TenantBrandingService::class)->sharedRamp(),
            ],
            // The tenant's read-only entitlement model (H5a / ADR-0008): current plan tier, feature flags,
            // and per-metric quota-vs-usage. One shared read-model both the H5b gated UI and any future
            // Plan & Usage panel consume, so they cannot disagree — the same "one source" reasoning as
            // ui.theme above. FAIL-CLOSED off-tenant: EntitlementService::snapshot() returns null when
            // there is no active tenant context (guest/central routes), so no query runs and no plan leaks.
            'entitlements' => app(EntitlementService::class)->snapshot(),
            // One-shot flash → toast bridge (design-system §3.7). Controllers signal an outcome with
            // ->with('toast', ['type' => 'success'|'error'|'info', 'message' => '…']); the app shell's
            // ToastHost raises it once, then the session flash is gone.
            'flash' => [
                // A controller's own toast always wins: it describes what THIS request did, which is more
                // specific than a standing notice about a request two hops ago.
                'toast' => $request->session()->get('toast') ?? $discardedToast,
                // Non-fatal XLSForm-import warnings (Increment G7b) — the builder renders these as a
                // dismissible banner after a destructive import so the author reviews lossy coercions
                // (dynamic repeat_count, downgraded grids, sanitized keys) before publishing (§6).
                'xlsformWarnings' => $request->session()->get('xlsformWarnings'),
                // Non-fatal branching notices raised after a PUBLISH (Increment H21a, Doc #27 §6): a
                // forward reference, a circular condition, or a form that shows nothing until something is
                // answered. Its own key rather than a reuse of `xlsformWarnings`, whose banner copy is
                // hard-coded to "Imported with N warning(s)" — the publish already succeeded and the
                // wording has to say so.
                'publishWarnings' => $request->session()->get('publishWarnings'),
                // The answers RELEVANCE dropped on the manual-encode channel (Increment H21c, Doc #27 §7):
                // the keyer typed them, Stage 3 masked them off, and the page used to say "Submission
                // recorded." over the loss. Its own key for the same reason `publishWarnings` is: this one is
                // an outcome report on a write that SUCCEEDED, so it must not borrow copy that says otherwise.
                'prunedAnswers' => $request->session()->get('prunedAnswers'),
                // Webhook one-shot flashes (H14). `newSecret` carries the plaintext signing secret exactly
                // once after a create/rotate so the page can reveal + copy it, then it is gone (never a durable
                // prop; AuditRedactor already strips it from the ledger). `testResult` carries the synchronous
                // test.ping outcome for the Show page's result modal — the tester never throws, so it is a plain
                // 200-shaped array. Both live for a single request via the session flash.
                'newSecret' => $request->session()->get('newSecret'),
                'testResult' => $request->session()->get('testResult'),
            ],
        ];
    }

    /**
     * Turn a step-up-discarded write into a toast, once, on the first page that is not the prompt.
     *
     * ⛔ THE KEY IS FORGOTTEN HERE AND NOWHERE ELSE, AND THE EXCLUSION IS THE POINT. `auth/ConfirmPassword`
     * reads the same key to render its inline notice and deliberately does not clear it, because a member
     * who reloads that page would otherwise lose the only explanation they were ever given. Clearing on the
     * first OTHER page is what makes it exactly-once: the confirmation prompt can be shown any number of
     * times, and the destination is told precisely once.
     *
     * ⚠️ THE PROMPT IS IDENTIFIED BY ROUTE, NOT BY REFERER OR BY URL SHAPE. Fortify registers
     * `password.confirm` for the GET and `password.confirm.store` for the POST, and both must be excluded
     * — clearing on the POST would consume the notice on the very request that makes the destination
     * reachable, which is the one hop where losing it is guaranteed.
     *
     * @return array{type: string, message: string}|null
     */
    protected function consumeDiscardedStepUpWrite(Request $request): ?array
    {
        $discarded = $request->session()->get('step_up.discarded');

        if (! is_array($discarded)) {
            return null;
        }

        if (in_array($request->route()?->getName(), ['password.confirm', 'password.confirm.store'], true)) {
            return null;
        }

        $request->session()->forget('step_up.discarded');

        return [
            'type' => 'error',
            'message' => 'Your change was not saved. You were asked to confirm your password first, '
                .'and the change was discarded — please make it again.',
        ];
    }
}
