<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Http\Middleware\InitializeTenancyByPublicHost;
use App\Models\Domain;
use App\Models\Tenant;

/**
 * Absolute URLs on a tenant's own host, for links that leave the application (Increment H17, split in H22a).
 *
 * `route()` cannot be used: a queued job has no request, so the URL generator has no host to build
 * against and would emit the central domain — which is exactly the host a tenant-scoped link must
 * NOT use, since tenancy resolves by host and `PreventAccessFromCentralDomains` rejects it.
 *
 * ── TWO ARMS, AND CHOOSING BETWEEN THEM IS A SECURITY DECISION (H22a) ───────────────────────────
 * {@see to()} is the APP arm and never returns a custom domain. {@see toPublic()} is the PUBLIC arm and
 * prefers one. The split exists because ADR-0009 §D2 scopes the Business-tier `custom_domain` feature to
 * "public forms, not the admin app": only routes/tenant.php's guest-runtime group carries
 * {@see InitializeTenancyByPublicHost}. Every other group still identifies by
 * subdomain, so an authenticated link built on a custom host would 302 the recipient to the central app.
 *
 *   to()        /attachments/…  (H17 pdf downloads)  ·  /invitations/{token}  (H3)
 *   toPublic()  /f/resume/{token}  (H9b) — the only one a RESPONDENT receives
 *
 * ⚠️ THIS CORRECTS A GUESS H17 MADE ABOUT H22's SHAPE. The previous implementation treated any stored
 * value containing a dot as a complete host and used it verbatim, "the shape H22 will introduce". That
 * turned out to be right about the storage and wrong about the consequence: a custom domain does not
 * serve the authenticated app, so `to()` must NOT reach for it.
 *
 * ── THE TWO DEFECTS THIS FIXES IN THE OLDER PRECEDENTS (recorded in H17, closed in H22a) ─────────
 * `TenantMembershipService::buildInviteAcceptUrl()` and `GuestDraftController::resumeUrl()` both did
 * `$tenant->domains()->value('domain') ?? $tenant->slug` and interpolated it into `"https://{$domain}/…"`.
 * Both halves were wrong: `domains.domain` holds the SUBDOMAIN LABEL, so they emitted `https://acme/…`,
 * which resolves nowhere; and the hard-coded `https://` is wrong in local development, which serves on
 * `http://acme.localhost:8080`. Both now delegate here, so every outbound tenant link in the application
 * is composed in exactly one place.
 *
 * ── DETERMINISM ─────────────────────────────────────────────────────────────────────────────────
 * `$tenant->domains()->value('domain')` is `first()` with NO ORDER BY, so once a tenant has two rows the
 * host depended on physical row order — the same class of defect as the double-subscription tie that made
 * a suite flip green-to-red because a file was added elsewhere in the tree. Both arms now select their row
 * by an explicit predicate and an explicit order.
 *
 * Visibility is not this class's job: {@see Domain}'s global scope already hides every custom domain that
 * is not live, so a half-provisioned hostname cannot reach an outbound link even through toPublic().
 */
final class TenantUrl
{
    /**
     * An absolute URL on the tenant's APP host — always `{label}.{deployment host}`, never a custom
     * domain. `$path` is appended verbatim and must be pre-encoded.
     */
    public static function to(Tenant $tenant, string $path): string
    {
        return self::scheme().'://'.self::appHost($tenant).'/'.ltrim($path, '/');
    }

    /**
     * An absolute URL on the tenant's PUBLIC host — its live custom domain if it has one, else the app
     * host. For links a respondent receives, which is the only audience the custom domain serves.
     */
    public static function toPublic(Tenant $tenant, string $path): string
    {
        return self::scheme().'://'.self::publicHost($tenant).'/'.ltrim($path, '/');
    }

    /**
     * The tenant's authenticated-app host: the DOTLESS `domains` row composed with the deployment's own
     * host, falling back to the slug if no row exists (which is the shape a freshly created tenant has).
     */
    private static function appHost(Tenant $tenant): string
    {
        $stored = $tenant->domains()->whereRaw("position('.' in domain) = 0")->orderBy('domain')->value('domain');
        $label = is_string($stored) && $stored !== '' ? $stored : (string) $tenant->slug;

        return $label.'.'.self::centralHost();
    }

    /**
     * The tenant's respondent-facing host. Only live custom domains are visible here at all (the model's
     * global scope), so this is a preference, not a filter.
     *
     * Ordered by activation, oldest first, then by name: a tenant with two live custom domains gets a
     * STABLE answer, because a resume link already in a respondent's inbox must keep pointing at the same
     * origin. Choosing the newest would silently repoint every outstanding link.
     */
    private static function publicHost(Tenant $tenant): string
    {
        $custom = $tenant->domains()
            ->whereRaw("position('.' in domain) > 0")
            ->orderBy('activated_at')
            ->orderBy('domain')
            ->value('domain');

        return is_string($custom) && $custom !== '' ? $custom : self::appHost($tenant);
    }

    /**
     * Host (plus port, if any) of the deployment — `localhost:8080`, `meridian.test`.
     *
     * `app.url` rather than `tenancy.central_domain` because only `app.url` carries the port, and
     * a link to `acme.localhost` with the `:8080` dropped is as broken as no link at all.
     */
    private static function centralHost(): string
    {
        $url = (string) config('app.url');
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return (string) config('tenancy.central_domain');
        }

        $port = parse_url($url, PHP_URL_PORT);

        return is_int($port) ? $host.':'.$port : $host;
    }

    /**
     * The deployment's scheme, defaulting to https.
     *
     * Defaults CLOSED: an unparseable or missing `app.url` yields https, so a misconfiguration
     * downgrades nobody to cleartext.
     */
    private static function scheme(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_SCHEME) === 'http' ? 'http' : 'https';
    }
}
