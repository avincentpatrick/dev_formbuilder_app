<?php

namespace App\Providers;

use App\Enums\ResourceScopeable;
use App\Models\Attachment;
use App\Models\Audit;
use App\Models\Form;
use App\Models\FormField;
use App\Models\PersonalAccessToken;
use App\Models\ResourceGrant;
use App\Models\ScopeNode;
use App\Models\Submission;
use App\Policies\AttachmentPolicy;
use App\Policies\AuditPolicy;
use App\Policies\FormPolicy;
use App\Policies\ResourceGrantPolicy;
use App\Policies\ScopeNodePolicy;
use App\Policies\SubmissionPolicy;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Entitlements\EntitlementService;
use App\Support\Guest\GuestShareTokenService;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\ServerVariable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The stateless guest share-token signer (Increment F5). Singleton so the derived key + configured TTL
        // are resolved once. The signing key is derived from APP_KEY with domain separation unless an explicit
        // GUEST_SHARE_TOKEN_KEY is set (for independent rotation); either way it is never a persisted secret.
        $this->app->singleton(GuestShareTokenService::class, function (): GuestShareTokenService {
            $configuredKey = config('guest.share_token.key');
            $key = is_string($configuredKey) && $configuredKey !== ''
                ? $configuredKey
                : hash_hmac('sha256', 'guest-share-token.v1', (string) config('app.key'));

            return new GuestShareTokenService($key, (int) config('guest.share_token.ttl'));
        });

        // The per-instance authorization resolver (Increment G10a). `scoped`, NOT `singleton`: it memoizes
        // a user's grants per (user, tenant) for the life of one request, and under Octane a singleton
        // would carry that memo — an authorization cache — across requests.
        $this->app->scoped(ResourceGrantResolver::class);

        // The single entitlement resolver (H5a / ADR-0008). Same reasoning as above: it memoizes the
        // current tenant's plan + usage per request, so `scoped` (reset per request under Octane), never
        // `singleton` (which would leak one tenant's plan into another's request).
        $this->app->scoped(EntitlementService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Per-form `.any`/`.own` authorization (Increment D2). Registered explicitly rather than relying
        // on auto-discovery so the mapping is greppable alongside the other RBAC wiring.
        Gate::policy(Form::class, FormPolicy::class);

        // Manual-encoding authorization (Increment F4b): `create` is gated per-form (permission + collaborator
        // scope + published) — the `can:create,<Submission>,form` route middleware resolves this policy from
        // the Submission class-string and passes the bound Form as the extra argument.
        Gate::policy(Submission::class, SubmissionPolicy::class);

        // Attachment read authorization (Increment G6): `view` gates the tenant-side signed serving of a
        // stored file behind the same per-form visibility the inbox uses. RLS already scopes to the tenant.
        Gate::policy(Attachment::class, AttachmentPolicy::class);

        // The tenant's scoping hierarchy (Increment G10a). Authoring a node IS authoring authorization
        // structure — a grant can be made against one — so it is Owner/Admin only, via `scopes.manage`.
        Gate::policy(ScopeNode::class, ScopeNodePolicy::class);

        // Granting access (Increment G10b) — the escalation surface itself, gated on
        // `forms.collaborators.manage` plus the no-self-grant and anti-amplification rules. Registration
        // is not optional decoration here: an unmapped model class falls through to Gate closures and
        // `before`, i.e. it fails OPEN rather than closed.
        Gate::policy(ResourceGrant::class, ResourceGrantPolicy::class);

        // Read-only access to the audit log (H4). Owner/Admin only, via `audit_log.view`. Registered
        // explicitly for the same fail-OPEN reason as the policies above.
        Gate::policy(Audit::class, AuditPolicy::class);

        // Polymorphic morph map — `attachments.attachable` (Increment G6, the repo's first `morphTo`) plus
        // `resource_grants.scopeable` (Increment G10a, the second). Store stable short aliases in the
        // *_type column (data-dictionary §10) instead of fully-qualified class names, so a namespace move
        // never rewrites persisted rows. NON-enforcing (morphMap, not enforceMorphMap): unmapped morphs
        // (Spatie's role/permission pivots, Sanctum's tokenable) keep resolving by their stored FQCN —
        // enforcing it broke exactly those and cost 90 test failures.
        //
        // The G10a aliases come from ResourceScopeable rather than being spelled again here: that enum is
        // also the source for the `scopeable_type` CHECK constraint and the RLS guard's per-alias EXISTS
        // branches, and the three must never disagree.
        //
        // H4's `audits.auditable_type` is DELIBERATELY not registered here. Its aliases include `users` and
        // `tenant`; adding those to the global map would rewrite how Sanctum's `tokenable_type` and Spatie's
        // `model_type` morphs serialize (User/Tenant), splitting old and new rows between alias and FQCN —
        // the same class of break enforceMorphMap caused. Audit rows store the spec §1 alias as a plain
        // string via AuditLogger and the read API surfaces it opaque, so no morph resolution is needed.
        Relation::morphMap(array_merge([
            'submission' => Submission::class,
            'form_field' => FormField::class,
        ], ResourceScopeable::morphMap()));

        // The tenant-scoped API-key model (Increment E) — auto-fills tenant_id at mint so the strict RLS
        // WITH CHECK on personal_access_tokens passes, and scopes lookups to the current tenant.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Per-token API rate limit (api-specification.md §2.5 — 600/min per API key). The `throttle:api`
        // middleware is priority-sorted AHEAD of authentication, so $request->user() is not yet resolved
        // here; key on the bearer-token hash (falling back to IP for a tokenless request, e.g. a 401).
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(600)->by(
            $request->bearerToken() !== null
                ? 'tok:'.hash('sha256', $request->bearerToken())
                : 'ip:'.$request->ip(),
        ));

        // Guest runtime limits (Increment F5). The submit/schema surface is limited per token AND per IP
        // (technical-architecture.md §7.2), so a single leaked link and a single enumerating IP are both
        // bounded; the mint surface is limited per IP. Keyed on the raw {shareToken} string (no verification
        // needed — this is velocity, not authenticity — so `throttle:guest` may run before the token middleware).
        RateLimiter::for('guest', fn (Request $request): array => [
            Limit::perMinute((int) config('guest.rate_limit.submit_per_token'))
                ->by('gtok:'.hash('sha256', (string) $request->route('shareToken'))),
            Limit::perMinute((int) config('guest.rate_limit.submit_per_ip'))
                ->by('gip:'.$request->ip()),
        ]);

        RateLimiter::for('guest-mint', fn (Request $request): Limit => Limit::perMinute(
            (int) config('guest.rate_limit.mint_per_ip'),
        )->by('gmint:'.$request->ip()));

        // OpenAPI 3.1 security scheme (Increment E). Scramble is a dev dependency; guard so a production
        // (`--no-dev`) install never touches its classes. The bearer scheme documents the Sanctum
        // personal-access-token auth used by the /api/v1 surface (api-specification.md §2.6 / §3).
        if (class_exists(Scramble::class)) {
            Scramble::extendOpenApi(function (OpenApi $openApi): void {
                $openApi->secure(SecurityScheme::http('bearer')->as('sanctumToken'));

                // Deterministic title + server so the committed openapi.json does not drift with APP_NAME /
                // APP_URL between environments (the CI contract job diffs a fresh export against the commit).
                $openApi->info->title = 'Form-Builder SaaS API';
                $openApi->servers = [
                    Server::make('https://{tenant}.meridian.test/api/v1')
                        ->setDescription('Per-tenant API base — replace {tenant} with your workspace subdomain slug.')
                        ->variables(['tenant' => new ServerVariable('acme')]),
                ];
            });
        }
    }
}
