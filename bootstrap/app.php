<?php

use App\Exceptions\Admin\SuperAdminException;
use App\Exceptions\Authorization\GrantException;
use App\Exceptions\Expressions\ExpressionEvaluationException;
use App\Exceptions\Expressions\ExpressionSyntaxException;
use App\Exceptions\Forms\FormException;
use App\Exceptions\Forms\PublishValidationException;
use App\Exceptions\Guest\ExpiredShareTokenException;
use App\Exceptions\Guest\InvalidShareTokenException;
use App\Exceptions\Jobs\InvalidJobPayloadException;
use App\Exceptions\Scoping\ScopeNodeException;
use App\Exceptions\Submissions\SubmissionConflictException;
use App\Exceptions\Submissions\SubmissionException;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Exceptions\Tenancy\MembershipException;
use App\Exceptions\Xlsform\XlsformImportException;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureSuperAdminMfa;
use App\Http\Middleware\EstablishTenantDatabaseContext;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\Api\ApiErrorResponse;
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
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Bridges resolved tenant/user → PostgreSQL RLS session variables (ADR-0002 §D3). Registered
        // as an alias; Increment B attaches it to the authenticated subdomain tenant route group,
        // immediately after stancl/tenancy's identification middleware.
        //
        // superadmin / superadmin.mfa (B2c) gate the central-domain console: is_super_admin flag +
        // mandatory confirmed 2FA (security-threat-model §8).
        //
        // ability / abilities (Increment E) are Sanctum's token-ability gates for the /api/v1 surface
        // (CheckForAnyAbility = any-of; CheckAbilities = all-of); not auto-registered by the package.
        $middleware->alias([
            'tenant.context' => EstablishTenantDatabaseContext::class,
            'superadmin' => EnsureSuperAdmin::class,
            'superadmin.mfa' => EnsureSuperAdminMfa::class,
            'ability' => CheckForAnyAbility::class,
            'abilities' => CheckAbilities::class,
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
        // the SPA reloads against the new version. Only the /api/v1 surface; a web (manual-encode) request
        // is an invariant violation there (the policy requires a published form) and keeps the default.
        $exceptions->render(fn (SubmissionException $e, Request $request) => $isApi($request)
            ? ApiErrorResponse::make(409, 'submission_version_superseded', $e->getMessage())
            : null);

        // Submission Pipeline CONTENT conflict (Increment G8c, offline-first-sync-design §5) — the same
        // client_submission_uuid was already persisted with materially DIFFERENT answers (a genuine concurrent
        // edit, not an idempotent replay). A distinct 409 code from submission_version_superseded so the offline
        // client can tell "the form changed" from "another copy of this response already exists"; both route to
        // the same review-and-resubmit UX. Only /api/v1; a web (manual-encode) request keeps the default.
        $exceptions->render(fn (SubmissionConflictException $e, Request $request) => $isApi($request)
            ? ApiErrorResponse::make(409, 'submission_conflict', $e->getMessage())
            : null);

        // Guest share-token failures (Increment F5). Thrown by EstablishGuestTenantContext BEFORE any tenant
        // context is set, so a forged/tampered/expired link never engages RLS. Both 401 on the /api/v1 surface;
        // the distinct codes let the guest SPA tell "invalid link" from "expired link, please request a new one".
        $exceptions->render(fn (InvalidShareTokenException $e, Request $request) => $isApi($request)
            ? ApiErrorResponse::make(401, 'invalid_share_token', 'This share link is invalid.')
            : null);

        $exceptions->render(fn (ExpiredShareTokenException $e, Request $request) => $isApi($request)
            ? ApiErrorResponse::make(401, 'share_token_expired', 'This share link has expired.')
            : null);

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
