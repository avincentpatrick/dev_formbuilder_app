<?php

use App\Exceptions\Admin\SuperAdminException;
use App\Exceptions\Analytics\InvalidAnalyticsQueryException;
use App\Exceptions\Authorization\GrantException;
use App\Exceptions\Connectors\InvalidConnectorStateException;
use App\Exceptions\Connectors\UnknownConnectorProviderException;
use App\Exceptions\Entitlements\FeatureGateException;
use App\Exceptions\Entitlements\QuotaExceededException;
use App\Exceptions\Entitlements\RateLimitExceededException;
use App\Exceptions\Expressions\ExpressionEvaluationException;
use App\Exceptions\Expressions\ExpressionSyntaxException;
use App\Exceptions\Forms\FormException;
use App\Exceptions\Forms\PublishValidationException;
use App\Exceptions\Guest\ExpiredShareTokenException;
use App\Exceptions\Guest\InvalidShareTokenException;
use App\Exceptions\Jobs\InvalidJobPayloadException;
use App\Exceptions\Scoping\ScopeNodeException;
use App\Exceptions\Submissions\FormNotAcceptingSubmissionException;
use App\Exceptions\Submissions\SubmissionConflictException;
use App\Exceptions\Submissions\SubmissionException;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Exceptions\Tenancy\MembershipException;
use App\Exceptions\Xlsform\XlsformImportException;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnforcePlatformMaintenance;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureSuperAdminMfa;
use App\Http\Middleware\EnsureVerifiedEmail;
use App\Http\Middleware\EstablishTenantDatabaseContext;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\InitializeTenancyByPublicHost;
use App\Http\Middleware\RequireFeature;
use App\Http\Middleware\RequireRecentPassword;
use App\Support\Api\ApiErrorResponse;
use App\Support\Auth\InvalidGoogleAuthStateException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Stancl\Tenancy\Exceptions\NotASubdomainException;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The super-admin console (Increment B2c) — a central-domain-only group, not the tenant
        // subdomain group (that is mapped by TenancyServiceProvider). Loaded inside the `web` group.
        //
        // The /api/v1 REST surface (Increment E) is loaded here too. Each group inside routes/api.php
        // declares its full middleware stack inline (token-consumed vs session-mint), so it is loaded
        // without wrapping middleware — mirroring how routes/tenant.php owns its own pipeline.
        then: function (): void {
            Route::middleware('web')->group(base_path('routes/admin.php'));
            Route::group([], base_path('routes/api.php'));
            // The native-connector OAuth callbacks (H15a) — central-domain, and deliberately NOT inside
            // `web`: a third-party GET from a consent screen carries no session and no CSRF token, and the
            // signed `state` parameter is the CSRF control (ADR-0009 §D3). The group declares its own stack.
            Route::group([], base_path('routes/connectors.php'));
            // First-party Google sign-in (J3c2 / ADR-0019) — the mint and the callback. Loaded here rather
            // than in routes/tenant.php because BOTH must also serve the central host, and that file
            // declares no ->domain(). ⚠️ Loaded BEFORE TenancyServiceProvider::mapRoutes(), which runs in
            // booted(): a same-URI route in routes/tenant.php would therefore be dead rather than
            // conflicting, which is why the completion hop there uses a path these two do not.
            // The group declares its own stack — Fortify's, not the connector one; see the file.
            Route::group([], base_path('routes/google-auth.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // ── THE SAML ASSERTION CONSUMER SERVICE IS THE ONLY CSRF-EXEMPT POST IN THIS APPLICATION (P1b) ──
        // It is a cross-origin form POST from a tenant's identity provider. There is no token to send, and
        // with SameSite=Lax no session cookie arrives either — which is not a gap, because this request
        // CREATES the session rather than acting on one. CSRF protects an EXISTING authenticated session
        // from being driven without its owner's intent; there is nothing here yet for it to protect.
        //
        // What replaces it is stronger than a token and is why `allow_unsolicited` is permanently false:
        // the assertion must be signed by the tenant's own trust anchor AND carry an `InResponseTo` naming
        // a live, unconsumed `sso_auth_requests` row this SP minted. A CSRF token only proves the browser
        // visited us first; neither of those can be forged by someone who has not compromised the IdP.
        //
        // BY EXACT PATH, not `sso/saml/*`: a wildcard would sweep in `/sso/saml/metadata` and every future
        // endpoint under that prefix, granting an exemption nobody decided to give — the
        // EnforcePlatformMaintenance path-list lesson, in the direction that REMOVES a control. The tenant
        // is identified from the host, so one entry covers every subdomain without naming any.
        $middleware->validateCsrfTokens(except: ['sso/saml/acs']);

        // Platform maintenance (I5 / PRD Feature #10) — GLOBAL, not `web`. "Blocks the entire product"
        // includes /api/v1 (routes/api.php declares its own stacks) and the connector callbacks
        // (routes/connectors.php sits outside `web` entirely), so a group mount would leave two holes that
        // each look fine until someone tries them. Exemptions are BY PATH — global middleware runs before
        // routing, so `$request->route()` is null and a name-based list would silently exempt nothing,
        // starting with the admin console. See EnforcePlatformMaintenance.
        $middleware->append(EnforcePlatformMaintenance::class);

        // Bridges resolved tenant/user → PostgreSQL RLS session variables (ADR-0002 §D3). Registered
        // as an alias; Increment B attaches it to the authenticated subdomain tenant route group,
        // immediately after stancl/tenancy's identification middleware.
        //
        // superadmin / superadmin.mfa (B2c) gate the central-domain console: is_super_admin flag +
        // mandatory confirmed 2FA (security-threat-model §8).
        //
        // ability / abilities (Increment E) are Sanctum's token-ability gates for the /api/v1 surface
        // (CheckForAnyAbility = any-of; CheckAbilities = all-of); not auto-registered by the package.
        //
        // step-up (I8a) — PRD Feature #14's re-authentication for high-blast-radius actions. A NARROWER
        // window (auth.step_up_timeout, 15 min) than the framework's `password.confirm` default of three
        // hours. ⚠️ Never mount it on a route a JSON sidecar calls: RequirePassword answers an
        // `Accept: application/json` request with a bare 423 instead of redirecting. See the class.
        // verified (J3a) — OVERRIDES the framework's own `verified` alias rather than adding a second name,
        // so every future `->middleware('verified')` gets the impersonation exemption and the documented
        // JSON envelope by default. Two things the stock EnsureEmailIsVerified cannot express; see the class.
        $middleware->alias([
            'verified' => EnsureVerifiedEmail::class,
            'tenant.context' => EstablishTenantDatabaseContext::class,
            'superadmin' => EnsureSuperAdmin::class,
            'superadmin.mfa' => EnsureSuperAdminMfa::class,
            'step-up' => RequireRecentPassword::class,
            'ability' => CheckForAnyAbility::class,
            'abilities' => CheckAbilities::class,
            // feature:<key> (H5c) — plan feature-gate on top of ability/can. Requires tenant context (all
            // gated routes have it); passes through a null (unseeded/dev) plan, blocks a resolved plan that
            // denies the feature. See RequireFeature.
            'feature' => RequireFeature::class,
        ]);

        // Middleware ordering (ADR-0002 §D3). The tenancy pipeline must ESTABLISH the RLS session
        // context BEFORE SubstituteBindings runs, or a route-bound tenant-scoped model (e.g.
        // /forms/{form}) is resolved with no tenant context and the RLS-scoped lookup 404s. The three
        // tenancy middleware are inserted after Authenticate (so EstablishTenantDatabaseContext can read
        // the authenticated user for app.current_user_id) and before SubstituteBindings. Priority only
        // reorders middleware a route already has — central/non-tenant routes are unaffected.
        //
        // AuthenticateApiToken (Increment E) sits immediately AFTER EstablishTenantDatabaseContext and
        // before SubstituteBindings: the Sanctum token lookup must run with the tenant GUC already set
        // (so the strict RLS on personal_access_tokens reveals only this tenant's token), and the
        // route-bound model must resolve with the now-known user. It is deliberately NOT the
        // `auth:sanctum` alias, which (implementing AuthenticatesRequests) would sort ahead of tenancy.
        //
        // InitializeTenancyByPublicHost (H22a) MUST BE LISTED HERE, and its slot is load-bearing.
        // TenancyServiceProvider::makeTenancyMiddlewareHighestPriority() calls
        // Kernel::prependToMiddlewarePriority() for six stancl classes, and that method is
        // membership-guarded (`if (! in_array($middleware, $this->middlewarePriority))`) — so a class
        // already in THIS array is skipped, while one that is absent is unshifted to index 0, ahead of
        // EncryptCookies, StartSession and AuthenticatesRequests. Naming it here is what keeps the
        // guest group's identification in the same slot InitializeTenancyBySubdomain occupies, rather
        // than silently promoting it to run before the session exists. Nothing about that promotion
        // would fail a test — the guest and invitation groups carry no `auth` at all — which is
        // exactly why TenancyMiddlewarePriorityTest asserts the resolved order instead of trusting
        // this comment. (The four stancl classes the provider does unshift stay inert: priority only
        // reorders middleware a route already carries, and no route carries them.)
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            AuthenticatesRequests::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuthenticatesSessions::class,
            InitializeTenancyByPublicHost::class,
            InitializeTenancyBySubdomain::class,
            PreventAccessFromCentralDomains::class,
            EstablishTenantDatabaseContext::class,
            AuthenticateApiToken::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A queued job whose payload cannot scope itself to a tenant (ADR-0007 §D2) is a PERMANENT
        // failure, not a transient one: the payload is immutable once enqueued, so retrying it three
        // times only triples the noise in failed_jobs. Straight to failed_jobs on the first attempt.
        $exceptions->dontRetry(InvalidJobPayloadException::class);

        // Render framework exceptions (validation, auth, model-not-found) as JSON for the JSON API surface
        // AND for any request that explicitly expects JSON — the builder's CSRF fetch sidecar (D4a) sends
        // Accept: application/json and needs a 422 body, not the HTML validation redirect Inertia visits get
        // (Inertia requests do not expectsJson(), so their redirect-with-errors flow is unaffected).
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // ── /api/v1 error envelope (api-specification.md §2.3): { error: { code, message, details } } ──
        // Each closure applies ONLY to the /api/v1 surface (returns null otherwise, so Inertia/web flows
        // are untouched — a returned null makes the handler fall through to the next callback/default).
        // IMPORTANT: render callbacks run AFTER Handler::prepareException, which rewrites several exceptions:
        // AuthorizationException / MissingAbilityException → AccessDeniedHttpException (the original kept as
        // getPrevious()), and ModelNotFoundException → NotFoundHttpException. So we match the POST-conversion
        // types here. ValidationException / AuthenticationException / ThrottleRequestsException / the domain
        // Form* exceptions are NOT rewritten and are matched directly.
        $isApi = fn (Request $request): bool => $request->is('api/v1/*');

        $exceptions->render(fn (ValidationException $e, Request $request) => $isApi($request)
            ? ApiErrorResponse::make(422, 'validation_failed', 'The given data was invalid.', ['fields' => $e->errors()])
            : null);

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            // A missing token ability keeps its MissingAbilityException as the previous exception.
            $previous = $e->getPrevious();
            if ($previous instanceof MissingAbilityException) {
                return ApiErrorResponse::make(403, 'insufficient_ability', 'This API token lacks a required ability.', ['missing' => $previous->abilities()]);
            }

            return ApiErrorResponse::make(403, 'forbidden', 'You are not authorized to perform this action.');
        });

        $exceptions->render(fn (AuthenticationException $e, Request $request) => $isApi($request)
            ? ApiErrorResponse::make(401, 'unauthenticated', 'Authentication is required.')
            : null);

        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $isApi($request)
            ? ApiErrorResponse::make(404, 'not_found', 'The requested resource was not found.')
            : null);

        $exceptions->render(fn (ThrottleRequestsException $e, Request $request) => $isApi($request)
            ? ApiErrorResponse::make(429, 'rate_limited', 'Too many requests.')->withHeaders($e->getHeaders())
            : null);

        $exceptions->render(fn (PublishValidationException $e, Request $request) => $isApi($request)
            ? ApiErrorResponse::make(422, 'publish_invalid', $e->getMessage())
            : null);

        $exceptions->render(fn (FormException $e, Request $request) => $isApi($request)
            ? ApiErrorResponse::make(422, 'form_rule_violated', $e->getMessage())
            : null);

        // Scoping-hierarchy rule violations (Increment G10b) — a move that would cycle, or one that would
        // push the subtree past the depth cap. Both are raised upfront inside move()'s transaction, before
        // the re-path statement, so this maps a deliberate refusal rather than dressing up a 23514.
        $exceptions->render(function (ScopeNodeException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiErrorResponse::make(422, $e->code(), $e->getMessage());
            }

            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        });

        // Grant escalation refusals (Increment G10b). The status travels ON the exception because the causes
        // split across two classes: an escalation refusal is a 403 (self-grant, anti-amplification) while a
        // bad request shape is a 422 (inactive recipient, descendants on a form target). See GrantException.
        $exceptions->render(function (GrantException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiErrorResponse::make($e->status(), $e->code(), $e->getMessage());
            }

            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        });

        // Hard-block quota refusals (H5b / ADR-0008 §D4) — a create/upload/invite past a provisioning-gauge
        // limit (forms_count / storage_bytes / active_seats). 402 Payment Required ("upgrade to proceed"),
        // with the metric-specific code + {metric, limit, used} details so an integration can branch on
        // exactly which ceiling was hit; a web request bounces back with an upgrade-prompt toast. A
        // respondent's submission is never-block and can never reach here.
        $exceptions->render(function (QuotaExceededException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiErrorResponse::make($e->status(), $e->code(), $e->getMessage(), $e->details());
            }

            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        });

        // Plan feature-gate refusals (H5c / ADR-0008 §D5) — a tenant reached a capability its plan does not
        // include (xlsform_export / offline_sync / form_templates / field_library / api_access). Same 402
        // entitlement-family status as a quota refusal, with the `feature_not_available` code + {feature}
        // detail; a web request bounces back with an upgrade-prompt toast. A grandfathered tenant's override
        // resolves the feature to true and never reaches here. See FeatureGateException / RequireFeature.
        $exceptions->render(function (FeatureGateException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiErrorResponse::make($e->status(), $e->code(), $e->getMessage(), $e->details());
            }

            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        });

        // An analytics declaration that violates one of ADR-0011 §D7's bounds, or a saved view's stored
        // definition that can no longer be read (H24a). A 422, never a 500: the bounds are enforced in
        // AnalyticsQuery's CONSTRUCTOR rather than only as validator rules — deliberately, so the saved-view
        // and export paths inherit them — which means a bad range arrives here as an exception rather than as
        // a field error. `reason` is machine-readable so a client can branch without parsing prose.
        //
        // H24b2 adds the WEB arm. Until it existed a hand-edited or bookmarked `/analytics?from=2024-01-01`
        // was a 500, because the 366-day span is deliberately NOT a validator rule (AnalyticsReportRequest's
        // docblock: expressing it there would be a second implementation of a bound that already has an
        // owner) and the exception extends InvalidArgumentException, not an HttpException.
        //
        // Scoped to `analytics.*`: everywhere else on the web the VO is built from hard-coded arguments
        // (DashboardMetricsService::trendsForUser), so reaching here off-surface is a server bug and keeps
        // its 500 rather than being dressed up as a user error.
        //
        // NOT `back()` on a GET. A bookmarked bad URL carries no referer, and `back()` would land on the
        // tenant subdomain's unrouted "/" — a 404 in place of the message. The bare index always builds a
        // valid default window, so redirecting to it cannot loop.
        $exceptions->render(function (InvalidAnalyticsQueryException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiErrorResponse::make(422, 'invalid_analytics_query', $e->getMessage(), ['reason' => $e->reason()]);
            }

            if (! $request->routeIs('analytics.*')) {
                return null;
            }

            $toast = ['type' => 'error', 'message' => $e->getMessage()];

            return $request->isMethod('GET')
                ? to_route('analytics.index')->with('toast', $toast)
                : back()->with('toast', $toast);
        });

        // Per-month usage-quota rate limit (H5c / ADR-0008 §D4) — the metered api_requests (and, when H13
        // ships webhook dispatch, webhook_deliveries) current-period usage reached the plan quota. 429 with a
        // Retry-After to the period reset, the same envelope as the burst throttle above so an integration's
        // existing 429 backoff handles it. API-only — a monthly API quota has no web surface.
        $exceptions->render(fn (RateLimitExceededException $e, Request $request) => $isApi($request)
            ? ApiErrorResponse::make($e->status(), $e->code(), $e->getMessage(), $e->details())->withHeaders($e->headers())
            : null);

        // XLSForm import failure (Increment G7b) — a malformed workbook rejected UPFRONT, before the
        // destructive draft-replace runs (§6). The API surface gets the stable code + {row,type} details it
        // carries; a web (builder) request bounces back with an error toast (the FormPublishController shape).
        $exceptions->render(function (XlsformImportException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiErrorResponse::make(422, $e->code(), $e->getMessage(), $e->details());
            }

            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        });

        // Submission Pipeline per-field failure (Increment F4b, Stages 1 & 3). The API surface gets the
        // structured 422 envelope; a web (manual-encode) request bounces back with the field errors keyed
        // `answers.<field>` so the Encode form binds each message to its input, plus an error toast.
        $exceptions->render(function (SubmissionValidationException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiErrorResponse::make(422, 'submission_invalid', $e->getMessage(), ['fields' => $e->fieldErrors()]);
            }

            $errors = [];
            foreach ($e->fieldErrors() as $fieldError) {
                // First message per field wins (withErrors keys are unique).
                $errors['answers.'.$fieldError['field']] ??= $fieldError['message'];
            }

            return back()->withErrors($errors)->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        });

        // Submission Pipeline STATE violation (Stage 2a — the version is not the currently published one).
        // Reaching here on the guest channel means the form was republished between the token mint and the
        // submit (the guest controller's own current-version check narrows the window; this is the race
        // backstop). Mapped to 409 rather than the generic 500 the missing closure previously produced —
        // the SPA reloads against the new version.
        //
        // ⚠️ THE WEB ARM EXISTS SINCE I9b, AND ITS ABSENCE WAS ARGUED CORRECTLY UNTIL THEN. The old comment
        // read: "a web (manual-encode) request is an invariant violation there (the policy requires a
        // published form) and keeps the default." True while `submit()` was the only web caller — its version
        // is resolved from `current_published_version_id`, so Stage 2a cannot fail. It is FALSE for
        // `promote()`, which re-asserts the DRAFT'S PINNED version: a keyer holding a v1 draft while an admin
        // publishes v2 now reaches this on a real, ordinary flow. Without an arm that is a 500 on a page the
        // user has just spent ten minutes on.
        $exceptions->render(fn (SubmissionException $e, Request $request) => $isApi($request)
            ? ApiErrorResponse::make(409, 'submission_version_superseded', $e->getMessage())
            : back()->with('toast', [
                'type' => 'error',
                'message' => 'This form has been updated since the draft was saved, so the draft can no longer be submitted.',
            ]));

        // Submission Pipeline CONTENT conflict (Increment G8c, offline-first-sync-design §5) — the same
        // client_submission_uuid was already persisted with materially DIFFERENT answers (a genuine concurrent
        // edit, not an idempotent replay). A distinct 409 code from submission_version_superseded so the offline
        // client can tell "the form changed" from "another copy of this response already exists"; both route to
        // the same review-and-resubmit UX. The draft save-vs-promote race (H9b) carries its own
        // `draft_already_finalized` code via SubmissionConflictException::code().
        //
        // ⚠️ THE WEB ARM EXISTS SINCE I9b, replacing "Only /api/v1; a web (manual-encode) request keeps the
        // default." Both causes are now reachable from the encode page: a resumed draft can race its own
        // promote (`draft_already_finalized`), and Stage 2b went live on this channel the moment it started
        // sending a `client_submission_uuid` (`submission_conflict` — two tabs on one draft, different
        // answers). The draft AUTOSAVE endpoint deliberately does NOT rely on this closure: it is a JSON
        // `fetch`, so it catches locally and returns a typed envelope, because a `back()` redirect is
        // unreadable to the composable driving it.
        $exceptions->render(fn (SubmissionConflictException $e, Request $request) => $isApi($request)
            ? ApiErrorResponse::make(409, $e->code(), $e->getMessage())
            : back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]));

        // Scheduled-form refusal (Increment H12a) — the form is not accepting a submission right now: not yet
        // open (`form_not_open`), closed past `closes_at` (`form_closed`), or its `max_responses` cap is full
        // (`max_responses_reached`, decided by a transactional COUNT-under-RLS at finalize). 403 (the form
        // exists; you may not submit to it now) with the boundary/cap figures in `details` so the guest SPA
        // (H12b) can render "opens soon"/"closed"/"full"; a web (manual-encode) request bounces back a toast.
        // A pre-close save-and-resume draft is exempt by the grace window and never reaches here on promote.
        $exceptions->render(function (FormNotAcceptingSubmissionException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiErrorResponse::make($e->status(), $e->code(), $e->getMessage(), $e->details());
            }

            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        });

        // Guest share-token failures (Increment F5). Thrown by EstablishGuestTenantContext BEFORE any tenant
        // context is set, so a forged/tampered/expired link never engages RLS. Both 401 on the /api/v1 surface;
        // the distinct codes let the guest SPA tell "invalid link" from "expired link, please request a new one".
        $exceptions->render(fn (InvalidShareTokenException $e, Request $request) => $isApi($request)
            ? ApiErrorResponse::make(401, 'invalid_share_token', 'This share link is invalid.')
            : null);

        $exceptions->render(fn (ExpiredShareTokenException $e, Request $request) => $isApi($request)
            ? ApiErrorResponse::make(401, 'share_token_expired', 'This share link has expired.')
            : null);

        // Native-connector OAuth `state` failures (H15a / ADR-0009 §D3). Thrown by
        // EstablishConnectorOauthContext BEFORE any tenant context is set, so a forged/tampered/expired/
        // wrong-provider state never engages RLS. The callback is a browser landing on the CENTRAL domain,
        // so the only useful response is a bounce to the app URL: there is no tenant host to return to (we
        // could not verify which tenant this was), and naming one would disclose whether it exists.
        $exceptions->render(fn (InvalidConnectorStateException $e, Request $request) => $isApi($request)
            ? ApiErrorResponse::make(400, 'invalid_connector_state', $e->getMessage())
            : redirect()->away((string) config('app.url')));

        // First-party Google sign-in `state` failures (J3c2 / ADR-0019 §D6). Thrown by
        // EstablishGoogleAuthContext BEFORE any tenant context is set, so a forged/tampered/expired state
        // never engages RLS. Same posture as the connector renderer above and one deliberate difference:
        // it lands on `/login?google=failed` rather than the bare app URL, because unlike a connector
        // callback this IS a sign-in and the person is mid-flow. The tenant host is NOT named — we could
        // not verify which workspace this was, and naming one would disclose whether it exists. The closed
        // `?google=failed` value is §D9's single indistinguishable outcome; nothing is echoed.
        $exceptions->render(fn (InvalidGoogleAuthStateException $e, Request $request) => $isApi($request)
            ? ApiErrorResponse::make(400, 'invalid_google_auth_state', $e->getMessage())
            : redirect()->away(rtrim((string) config('app.url'), '/').'/login?google=failed'));

        // A provider key with no configured adapter (H15a) — an unknown URL, not a server fault. 404 keeps
        // the non-disclosure posture the rest of the surface uses for "this does not exist".
        $exceptions->render(function (UnknownConnectorProviderException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiErrorResponse::make(404, 'unknown_connector_provider', 'This integration is not available.');
            }

            throw new NotFoundHttpException;
        });

        // Submit-time expression failure — a defensive backstop. Published expressions are pre-validated by
        // the F3 ExpressionValidationGate at publish, so reaching here signals a server bug, not user error;
        // the handler still report()s it. Surface a generic failure rather than a raw 500 / HTML.
        $exceptions->render(function (ExpressionSyntaxException|ExpressionEvaluationException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiErrorResponse::make(422, 'expression_error', 'A form expression could not be evaluated.');
            }

            return back()->with('toast', [
                'type' => 'error',
                'message' => 'This form could not be processed. Please try again or contact support.',
            ]);
        });

        // Tenancy identification failure — every tenant route is served on a tenant subdomain, so a request
        // for one on the central/non-subdomain host (localhost, 127.0.0.1, the apex) or an unknown subdomain
        // must not surface a raw 500. The /api/v1 surface answers with a 404 envelope; a browser (web) request
        // is redirected to the central app home (e.g. a stray navigation or a post-login redirect that landed
        // on the central host without a workspace subdomain).
        $exceptions->render(function (NotASubdomainException|TenantCouldNotBeIdentifiedOnDomainException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiErrorResponse::make(404, 'tenant_not_identified', 'No tenant could be identified for this host.');
            }

            $central = config('app.url');

            return redirect(is_string($central) && $central !== '' ? $central : '/');
        });

        // Final fallback so NOTHING on /api/v1 escapes the envelope as a raw 500 / HTML / framework JSON:
        // e.g. a 419 CSRF TokenMismatch on the session mint routes, or any unexpected error. Registered last
        // so the specific closures above win; the exception is still logged by the handler's report() path.
        $exceptions->render(function (Throwable $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            [$code, $message] = match (true) {
                $status === 419 => ['csrf_token_mismatch', 'The CSRF token is missing or invalid.'],
                $status >= 500 => ['server_error', 'An unexpected error occurred.'],
                default => ['request_failed', $e->getMessage() !== '' ? $e->getMessage() : 'The request could not be processed.'],
            };

            return ApiErrorResponse::make($status, $code, $message);
        });

        // Membership business-rule violations (B2b) are user-facing, not 500s: bounce back with a
        // validation-style error so the form (or the JSON api/* path) surfaces the reason.
        $exceptions->render(fn (MembershipException $e) => back()->withErrors(['membership' => $e->getMessage()]));

        // Super-admin business-rule violations (B2c) — same posture (e.g. suspending an already-suspended
        // tenant): a user-facing redirect-back-with-error, not a 500.
        $exceptions->render(fn (SuperAdminException $e) => back()->withErrors(['admin' => $e->getMessage()]));
    })->create();
