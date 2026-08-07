<?php

namespace App\Providers;

use App\Enums\ResourceScopeable;
use App\Models\Attachment;
use App\Models\Audit;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Notification;
use App\Models\PersonalAccessToken;
use App\Models\ResourceGrant;
use App\Models\SavedReportView;
use App\Models\ScopeNode;
use App\Models\Submission;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Policies\AttachmentPolicy;
use App\Policies\AuditPolicy;
use App\Policies\ConnectionPolicy;
use App\Policies\ConnectionSubscriptionPolicy;
use App\Policies\FormPolicy;
use App\Policies\NotificationPolicy;
use App\Policies\ResourceGrantPolicy;
use App\Policies\SavedReportViewPolicy;
use App\Policies\ScopeNodePolicy;
use App\Policies\SubmissionPolicy;
use App\Policies\WebhookEndpointPolicy;
use App\Services\Analytics\AnalyticsFormSet;
use App\Services\Analytics\AnalyticsMetricsService;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Dashboard\DashboardMetricsService;
use App\Services\Entitlements\EntitlementService;
use App\Services\Entitlements\QuotaGuard;
use App\Services\Settings\PlatformSettings;
use App\Services\Settings\TenantSettingRegistry;
use App\Support\Connectors\ConnectorOAuthStateService;
use App\Support\Guest\GuestChallengeService;
use App\Support\Guest\GuestShareTokenService;
use App\Support\Tenancy\DnsTxtResolver;
use App\Support\Tenancy\SystemDnsTxtResolver;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\ServerVariable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
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

            // The resume token (H9b) is signed with an independently domain-separated key so a share token
            // and a resume token can never validate as each other, even before the claim-layer typ/sid checks.
            $configuredResumeKey = config('guest.resume_token.key');
            $resumeKey = is_string($configuredResumeKey) && $configuredResumeKey !== ''
                ? $configuredResumeKey
                : hash_hmac('sha256', 'guest-resume-token.v1', (string) config('app.key'));

            return new GuestShareTokenService(
                $key,
                (int) config('guest.share_token.ttl'),
                $resumeKey,
                (int) config('guest.resume_token.ttl'),
            );
        });

        // The guest proof-of-work challenge signer (I8b) — the FOURTH member of this key family, minted
        // here beside the other three and domain-separated the same way, so a share, resume, state or
        // challenge value can never validate as another. Set GUEST_CHALLENGE_KEY to rotate it independently.
        $this->app->singleton(GuestChallengeService::class, function (): GuestChallengeService {
            $configuredKey = config('guest.challenge.key');
            $key = is_string($configuredKey) && $configuredKey !== ''
                ? $configuredKey
                : hash_hmac('sha256', 'guest-challenge.v1', (string) config('app.key'));

            return new GuestChallengeService(
                $this->app->make(CacheRepository::class),
                $key,
                (int) config('guest.challenge.ttl'),
                (int) config('guest.challenge.max_number'),
            );
        });

        // The native-connector OAuth `state` signer (H15a / ADR-0009 §D3) — the third token family, and the
        // only carrier of tenant + user identity across the host boundary to the central-domain callback
        // (the session cookie is host-only, so a tenant session is unreadable there). Singleton so the
        // derived key + TTL resolve once. The key is domain-separated from the two guest keys above, so a
        // share, resume or state token can never validate as another; set CONNECTOR_STATE_KEY to rotate it
        // independently of APP_KEY.
        $this->app->singleton(ConnectorOAuthStateService::class, function (): ConnectorOAuthStateService {
            $configuredKey = config('connectors.state.key');
            $key = is_string($configuredKey) && $configuredKey !== ''
                ? $configuredKey
                : hash_hmac('sha256', 'connector-oauth-state.v1', (string) config('app.key'));

            return new ConnectorOAuthStateService($key, (int) config('connectors.state.ttl', 600));
        });

        // The per-instance authorization resolver (Increment G10a). `scoped`, NOT `singleton`: it memoizes
        // a user's grants per (user, tenant) for the life of one request, and under Octane a singleton
        // would carry that memo — an authorization cache — across requests.
        $this->app->scoped(ResourceGrantResolver::class);

        // The single entitlement resolver (H5a / ADR-0008). Same reasoning as above: it memoizes the
        // current tenant's plan + usage per request, so `scoped` (reset per request under Octane), never
        // `singleton` (which would leak one tenant's plan into another's request).
        $this->app->scoped(EntitlementService::class);

        // The two halves of the `settings` store (I5 / PRD Feature #10). `scoped` for the same reason as
        // everything above AND for one more that is specific to settings: a `singleton` memo would mean an
        // operator switching platform maintenance ON does not take effect on a long-lived worker until it
        // restarts — a toggle that appears to do nothing. EntitlementService takes the tenant registry as a
        // constructor dependency, so scoping both is what keeps them the SAME instance (and therefore one
        // query) within a request.
        $this->app->scoped(TenantSettingRegistry::class);
        $this->app->scoped(PlatformSettings::class);

        // The hard-block quota guard (H5b). `scoped` so it shares the request's one EntitlementService
        // instance (its live-gauge memo), the same reason the resolvers above are scoped.
        $this->app->scoped(QuotaGuard::class);

        // The dashboard KPI aggregator (H11). `scoped` so it shares the request's one ResourceGrantResolver
        // (its per-user grant memo, used to scope a Form Editor/Reviewer's counts), the same reason the
        // resolvers above are scoped — never `singleton`, which would leak that memo across requests.
        $this->app->scoped(DashboardMetricsService::class);

        // The cross-form analytics aggregators (H24a, ADR-0011). Scoped for exactly the reason above —
        // AnalyticsFormSet resolves every query through the request's one ResourceGrantResolver memo, so a
        // singleton would leak one user's grant set into the next request.
        $this->app->scoped(AnalyticsFormSet::class);
        $this->app->scoped(AnalyticsMetricsService::class);

        // Custom-domain TXT lookup (H22a / ADR-0012). `singleton`, NOT `scoped`, and the difference is
        // deliberate rather than incidental: every `scoped` binding above is scoped BECAUSE it memoizes
        // per-request state (a user's grants, a tenant's plan) that must not leak across requests under
        // Octane. This one is stateless — it holds no tenant, no user and no cache — so a singleton is
        // correct and cheaper.
        //
        // An interface at all because DNS is not HTTP: Http::fake() cannot reach dns_get_record(), so
        // this seam is the only way the verification sweep is testable. It is also the swap point if the
        // Windows host's DNS_TXT support proves inadequate — see SystemDnsTxtResolver.
        $this->app->singleton(DnsTxtResolver::class, SystemDnsTxtResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ── Markdown-mail output encoding (H23a4) — closes a LIVE defect, not a hardening nicety ───────
        // security-threat-model.md §5 carried this as "Open — a live defect, assigned" to H3/H23: Laravel's
        // markdown mailer runs `{{ }}` values through htmlspecialchars before CommonMark sees them, so
        // SCRIPT is already blocked — but `[` is not, which makes markdown LINK and IMAGE syntax live in
        // every interpolated tenant name, form name and webhook label. A tenant named
        // `[Reset your password](https://evil.example)` would have rendered a working phishing link inside
        // a platform-branded email, and `![x](https://evil.example/px.gif)` a remote tracking pixel.
        //
        // H23a4 is the increment that owns this surface (it builds the mail template layer the row was
        // waiting on), so it closes it here rather than inheriting it. Enabling secured encoding escapes
        // `[`, `<` and `>` in every markdown-mail interpolation.
        //
        // RESIDUAL, stated rather than implied: `*`, `_` and backticks stay live, so an adversarial name
        // can still render italic or as a code span. That is typography, not a security control — both
        // vectors the threat model names need `[`, and both are closed.
        Markdown::withSecuredEncoding();

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

        // Webhook endpoints (H13a). Owner/Admin only, via `webhooks.manage`. Registered explicitly for the
        // same fail-OPEN reason as the policies above.
        Gate::policy(WebhookEndpoint::class, WebhookEndpointPolicy::class);

        // Saved analytics reports (H24a, ADR-0011 §D8/§D9). Registered explicitly for the same fail-OPEN
        // reason. This policy does double duty: its `viewAny` is also the `can:` gate on the analytics report
        // and question routes, which have no model of their own — see SavedReportViewPolicy's docblock for
        // why `can:viewAny,Submission::class` was rejected there.
        Gate::policy(SavedReportView::class, SavedReportViewPolicy::class);

        // Native-connector OAuth grants (H15a). Owner/Admin only, via the new `integrations.manage`
        // permission. Registered explicitly for the same fail-OPEN reason as the policies above. The /api/v1
        // subscription routes are all nested under a bound Connection, so authorization there is decided on
        // the grant that owns the rule.
        Gate::policy(Connection::class, ConnectionPolicy::class);

        // Delivery rules on a connection (H15b). The SAME `integrations.manage` check, split out because the
        // Integrations UI gives a rule its own page and therefore flat routes with no {connection} binding to
        // gate on — and a nested one would 404 after a disconnect, which soft-deletes the grant but keeps its
        // rules. See ConnectionSubscriptionPolicy's docblock.
        Gate::policy(ConnectionSubscription::class, ConnectionSubscriptionPolicy::class);

        // In-app notifications (I4). No permission is consulted — the single `markRead` ability is an
        // OWNERSHIP check, because `notifications` is strict-RLS and therefore tenant-scoped rather than
        // user-scoped, exactly as SavedReportView is. Registered explicitly for the same fail-OPEN reason as
        // the policies above; without the mapping `can:markRead,notification` would fall through to Gate
        // closures and allow a co-tenant to mark a colleague's notification read.
        Gate::policy(Notification::class, NotificationPolicy::class);

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
            // H13b: an oversized webhook delivery envelope archived to attachment storage is owned by its
            // delivery row (WebhookPayloadArchive), so `attachments.attachable_type` stores this short alias.
            'webhook_delivery' => WebhookDelivery::class,
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

        // The proof-of-work challenge endpoint (I8b). ⚠️ ITS OWN LIMITER, NOT `guest`: sharing that bucket
        // would make every submission cost TWO requests against `submit_per_token`, silently halving the
        // documented 30/min submit ceiling to 15/min — a limit the operator set for one thing quietly
        // enforcing something else. Ceilings sit above the submit ones because a client may legitimately
        // re-solve after a 403 (the api-client's retry-once), and because issuing costs one HMAC.
        RateLimiter::for('guest-challenge', fn (Request $request): array => [
            Limit::perMinute((int) config('guest.rate_limit.challenge_per_token'))
                ->by('gch:'.hash('sha256', (string) $request->route('shareToken'))),
            Limit::perMinute((int) config('guest.rate_limit.challenge_per_ip'))
                ->by('gchip:'.$request->ip()),
        ]);

        // Native-connector OAuth callback (H15a / ADR-0009). An unauthenticated public endpoint on the
        // central domain: a real tenant reaches it once per connection, so a per-IP ceiling this low costs
        // nothing legitimate while bounding an attacker grinding forged `state` values against it.
        RateLimiter::for('connector-oauth', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('coauth:'.$request->ip()));

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
