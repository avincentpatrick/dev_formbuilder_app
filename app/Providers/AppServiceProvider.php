<?php

namespace App\Providers;

use App\Models\Form;
use App\Models\PersonalAccessToken;
use App\Models\Submission;
use App\Policies\FormPolicy;
use App\Policies\SubmissionPolicy;
use App\Support\Guest\GuestShareTokenService;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\ServerVariable;
use Illuminate\Cache\RateLimiting\Limit;
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
